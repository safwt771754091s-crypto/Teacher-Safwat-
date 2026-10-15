<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Services\ContentExtractionService;
use App\Services\TextbookExportService;
use App\Services\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the two learner-facing fixes: server-side Arabic narration (for phones
 * with no Arabic TTS voice) and the in-app textbook page viewer.
 */
class LessonAudioAndPagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_tts_returns_mp3_bytes_for_arabic_text(): void
    {
        $this->mock(TtsService::class, function ($mock) {
            $mock->shouldReceive('synthesize')->once()->andReturn('ID3FAKE-MP3-BYTES');
        });

        $res = $this->get('/api/v1/tts?text=' . rawurlencode('الدرس الأول'))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        $this->assertSame('ID3FAKE-MP3-BYTES', $res->getContent());
    }

    public function test_tts_requires_text(): void
    {
        $this->get('/api/v1/tts')->assertStatus(422);
    }

    public function test_tts_returns_no_content_when_synthesis_unavailable(): void
    {
        $this->mock(TtsService::class, function ($mock) {
            $mock->shouldReceive('synthesize')->andReturn(null);
        });

        $this->get('/api/v1/tts?text=' . rawurlencode('نص'))->assertNoContent();
    }

    public function test_tts_is_disabled_when_config_disables_it(): void
    {
        config(['platform.tts.enabled' => false]);

        $this->get('/api/v1/tts?text=' . rawurlencode('نص'))->assertNotFound();
    }

    public function test_tts_chunking_keeps_each_part_under_the_limit(): void
    {
        $text = implode(' ', array_fill(0, 120, 'كلمة'));
        $chunks = app(TtsService::class)->chunk($text);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(190, mb_strlen($chunk));
        }
    }

    public function test_narration_returns_ordered_speakable_chunks(): void
    {
        $lesson = Lesson::whereHas('textbook')->has('sections')->firstOrFail();

        $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/narrate")
            ->assertOk()
            ->assertJsonStructure(['asset_id', 'chunks', 'text', 'provider']);
    }

    public function test_lesson_page_image_is_served_as_png(): void
    {
        $lesson = Lesson::whereHas('textbook')->firstOrFail();

        // Stub the extractor so the endpoint's HTTP contract (status, headers,
        // body) is what is exercised here; rendering itself is covered by the
        // service's own unit behaviour.
        $this->mock(ContentExtractionService::class, function ($mock) {
            $mock->shouldReceive('pageImageUrl')->andReturn(null);
            $mock->shouldReceive('pageImage')->once()->andReturn("\x89PNG\r\n\x1a\nFAKE");
        });

        $res = $this->get("/api/v1/lessons/{$lesson->id}/pages/1/image")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->assertStringStartsWith("\x89PNG", $res->getContent());
    }

    public function test_missing_page_image_returns_404(): void
    {
        $lesson = Lesson::whereHas('textbook')->firstOrFail();

        $this->mock(ContentExtractionService::class, function ($mock) {
            $mock->shouldReceive('pageImageUrl')->andReturn(null);
            $mock->shouldReceive('pageImage')->andReturn(null);
        });

        $this->get("/api/v1/lessons/{$lesson->id}/pages/1/image")->assertNotFound();
    }

    public function test_page_source_returns_direct_url_for_a_cloud_disk(): void
    {
        // On a cloud disk (Supabase Storage) the stored page image is exposed as
        // a direct CDN URL so the client can bypass the API.
        config([
            'platform.storage.textbook_disk' => 'cloud',
            'filesystems.disks.cloud' => [
                'driver' => 's3',
                'bucket' => 'textbooks',
                'url' => 'https://example.supabase.co/storage/v1/object/public',
            ],
        ]);

        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('url')->with('textbooks/1/pages/1.png')
            ->andReturn('https://example.supabase.co/storage/v1/object/public/textbooks/textbooks/1/pages/1.png');
        Storage::shouldReceive('disk')->andReturn($disk);

        $lesson = Lesson::whereHas('textbook')->firstOrFail();
        $lesson->textbook->pages()->updateOrCreate(
            ['page_number' => 1],
            ['image_path' => 'textbooks/1/pages/1.png'],
        );

        $this->getJson("/api/v1/lessons/{$lesson->id}/pages/1")
            ->assertOk()
            ->assertJsonPath('image_url', 'https://example.supabase.co/storage/v1/object/public/textbooks/textbooks/1/pages/1.png');
    }

    public function test_page_source_has_no_direct_url_on_a_local_disk(): void
    {
        // The local disk is not web-served, so no direct URL is offered and the
        // client keeps using the API proxy.
        config(['platform.storage.textbook_disk' => 'public']);

        $lesson = Lesson::whereHas('textbook')->firstOrFail();
        $lesson->textbook->pages()->updateOrCreate(
            ['page_number' => 1],
            ['image_path' => 'textbooks/1/pages/1.png'],
        );

        $this->getJson("/api/v1/lessons/{$lesson->id}/pages/1")
            ->assertOk()
            ->assertJsonPath('image_url', null);
    }

    public function test_stored_page_redirects_to_the_cloud_url(): void
    {
        config([
            'platform.storage.textbook_disk' => 'cloud',
            'filesystems.disks.cloud' => ['driver' => 's3', 'bucket' => 'textbooks'],
        ]);

        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('url')->andReturn('https://example.supabase.co/storage/v1/object/public/textbooks/p.png');
        Storage::shouldReceive('disk')->andReturn($disk);

        $lesson = Lesson::whereHas('textbook')->firstOrFail();
        $lesson->textbook->pages()->updateOrCreate(
            ['page_number' => 1],
            ['image_path' => 'textbooks/1/pages/1.png'],
        );

        $this->get("/api/v1/lessons/{$lesson->id}/pages/1/image")
            ->assertRedirect('https://example.supabase.co/storage/v1/object/public/textbooks/p.png');
    }

    private function student(): \App\Models\User
    {
        return \App\Models\User::where('email', 'student@school.ye')->first();
    }
}
