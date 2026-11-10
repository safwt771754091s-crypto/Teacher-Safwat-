<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiTeacherTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_explanation_is_grounded_in_lesson_source(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();

        // No LLM: the platform composes the explanation from the lesson source
        // itself, so the test is deterministic and needs no network.
        config(['platform.ai.provider' => 'none']);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/explain", [])
            ->assertOk();

        $this->assertTrue($res->json('grounded'));
        $this->assertNotEmpty($res->json('pages'));
        // Every point must come from the lesson content, not invent new facts.
        $joined = implode(' ', $res->json('explanation'));
        $this->assertStringContainsString('الجمع', $joined);
        $this->assertStringContainsString('زائد', $joined);
        // Each explained point carries its textbook page so the student can
        // verify it, rather than trusting a free-floating claim.
        $this->assertStringContainsString('صفحة', $joined);
    }

    public function test_lesson_without_content_does_not_invent(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $lesson->sections()->delete();
        $lesson->summaries()->delete();

        config(['platform.ai.provider' => 'none']);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/explain", [])
            ->assertOk();

        $this->assertTrue($res->json('needs_content'));
        $res->assertJsonPath('grounded', true);
    }

    public function test_asking_a_question_returns_source_scoped_answer(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        config(['platform.ai.provider' => 'none']);
        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/ask", ['question' => 'ما علامة الجمع؟'])
            ->assertOk();
        $this->assertTrue($res->json('grounded'));
    }

    public function test_an_invalid_key_at_call_time_is_logged_with_the_provider_reason(): void
    {
        // The key was accepted when saved, then expired. The owner's only clue is
        // the usage log, so it must carry the provider's own words, not just
        // "failed" — otherwise an expired key looks identical to a network blip.
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();

        config(['platform.ai.provider' => 'openai', 'platform.ai.api_key' => 'sk-expired']);

        Http::fake(['*' => Http::response([
            'error' => ['code' => 'invalid_api_key', 'message' => 'Incorrect API key provided.'],
        ], 401)]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/explain", [])
            ->assertOk();

        $log = AiUsageLog::where('feature', 'explain')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->success);
        $this->assertStringContainsString('401', (string) $log->error);
        $this->assertStringContainsString('invalid_api_key', (string) $log->error);
        $this->assertStringContainsString('Incorrect API key', (string) $log->error);
    }

    public function test_summary_endpoint_returns_draft(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الطرح حتى ١٠')->firstOrFail();
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/lessons/{$lesson->id}/summary")
            ->assertOk()
            ->assertJsonStructure(['summary' => ['points', 'source_pages']]);
    }

    public function test_the_free_keyless_provider_is_the_default(): void
    {
        // Out of the box (no env, no owner setting) the teacher must work with no
        // signup and no credit card, so the default provider is the keyless one.
        $this->assertSame('pollinations', config('platform.ai.provider'));
        $this->assertContains(config('platform.ai.provider'), config('platform.ai.keyless'));
    }

    public function test_a_keyless_provider_answers_without_an_api_key(): void
    {
        // The free provider answers with no key at all. The teacher must reach it
        // with an empty key instead of crashing on a null: a keyless provider is
        // exactly the path an owner uses to switch the teacher on for free.
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();

        config([
            'platform.ai.provider' => 'pollinations',
            'platform.ai.base_url' => 'https://text.pollinations.ai/openai',
            'platform.ai.model' => 'openai',
            'platform.ai.api_key' => null,
        ]);

        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'شرح من الكتاب (صفحة 5)']]],
        ], 200)]);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/explain", [])
            ->assertOk();

        $this->assertSame('pollinations', $res->json('provider'));
        $this->assertTrue($res->json('grounded'));
    }
}
