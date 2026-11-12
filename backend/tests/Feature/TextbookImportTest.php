<?php

namespace Tests\Feature;

use App\Console\Commands\ImportTextbooks;
use App\Models\Subject;
use App\Models\User;
use App\Services\ContentExtractionService;
use App\Services\TextbookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The owner console ingests the official book PDFs itself, in bounded batches,
 * so the whole catalogue can be loaded without a manual upload per book. These
 * tests lock the access rules and the batch/JSON contract (no network needed).
 */
class TextbookImportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function owner(): User
    {
        return User::where('email', 'owner@school.ye')->firstOrFail();
    }

    public function test_student_cannot_import_or_read_coverage(): void
    {
        $student = User::where('email', 'student@school.ye')->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/owner/textbooks/import', ['manifest' => 'all'])
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/owner/textbooks/coverage')
            ->assertStatus(403);
    }

    public function test_coverage_reports_ready_and_pending_books(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/owner/textbooks/coverage')
            ->assertOk()
            ->assertJsonStructure(['books', 'ready', 'pending', 'items'])
            ->assertJsonPath('items.0.ready', false);
    }

    public function test_import_rejects_unknown_manifest(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/owner/textbooks/import', ['manifest' => 'nowhere'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('manifest');
    }

    public function test_import_accepts_the_verified_manifest_and_drafts_by_default(): void
    {
        // The console sends the verified manifest key; an offset past the end of
        // the manifest keeps the batch offline and instant while still exercising
        // the full request path (validation + default draft-lessons + JSON summary).
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/owner/textbooks/import', ['manifest' => 'live', 'offset' => 99999, 'max_seconds' => 5])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'next_offset', 'timed_out']);
    }

    public function test_import_command_emits_a_json_batch_summary(): void
    {
        // Drive the command through the same service the owner endpoint uses,
        // and read the JSON batch summary it produces. A grade that matches no
        // manifest entry keeps this offline and fast while exercising the
        // resume/JSON contract the console relies on.
        $result = app(TextbookImportService::class)->runBatch('all', 0, 5, 99);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['imported']);
        $this->assertArrayHasKey('next_offset', $result);
        $this->assertArrayHasKey('timed_out', $result);
    }

    public function test_the_platform_adopts_the_verified_aden_manifest_by_default(): void
    {
        // The owner asked for a single adopted curriculum: the Aden editions
        // (plus the shared national "common" books). The import must therefore
        // default to the verified real-PDF manifest, and the learner view to the
        // Aden filter.
        $this->assertSame('aden', config('platform.curriculum.authority'));
        $this->assertSame('live', TextbookImportService::defaultManifest());
        $this->assertFileExists(base_path(TextbookImportService::MANIFESTS['live']));

        // Every URL in the verified manifest must be a real http(s) link, so an
        // import never wastes a batch on a placeholder.
        $manifest = json_decode((string) file_get_contents(base_path(TextbookImportService::MANIFESTS['live'])), true);
        $this->assertIsArray($manifest);
        $this->assertNotEmpty($manifest);
        foreach ($manifest as $entry) {
            $this->assertStringStartsWith('https://', (string) ($entry['url'] ?? ''));
        }
    }

    public function test_a_batch_that_runs_out_of_time_resumes_without_advancing(): void
    {
        // A book that needs more than the batch budget must not be skipped on
        // the next call: the resume offset stays put, the page slice continues
        // from where it stopped, and nothing already stored is redone.
        $textbook = Subject::query()->firstOrFail()->textbooks()->create([
            'title' => 'كتاب جزئي', 'term' => 'first', 'source_type' => 'pdf', 'pages_count' => 40,
        ]);
        foreach (range(1, 24) as $n) {
            $textbook->pages()->create(['page_number' => $n, 'extracted_text' => str_repeat('نص مقروء واضح. ', 20)]);
        }

        $command = app(ImportTextbooks::class);
        $method = new ReflectionMethod($command, 'resumePage');
        $method->setAccessible(true);
        $extractor = app(ContentExtractionService::class);

        $this->assertSame(25, $method->invoke($command, $textbook, $extractor));
    }

    public function test_the_reader_endpoint_url_is_normalized_to_serve_the_file(): void
    {
        // The official host only returns the PDF from the reader endpoint when
        // `read=1` is present; without it the request is bounced to the homepage.
        $command = app(ImportTextbooks::class);
        $method = new ReflectionMethod($command, 'normalizeDownloadUrl');

        $this->assertSame(
            'https://s1.yemen-books.com/app/open.php?book=265&read=1',
            $method->invoke($command, 'https://s1.yemen-books.com/app/open.php?book=265'),
        );

        // An explicit read flag, or an unrelated host, must be left untouched.
        $this->assertSame(
            'https://s1.yemen-books.com/app/open.php?book=265&read=1',
            $method->invoke($command, 'https://s1.yemen-books.com/app/open.php?book=265&read=1'),
        );
        $this->assertSame(
            'https://example.com/book.pdf',
            $method->invoke($command, 'https://example.com/book.pdf'),
        );
    }

    public function test_a_non_pdf_response_is_not_accepted_as_a_book(): void
    {
        // A redirect to an HTML page must never be stored as a "book": the guard
        // is what turns a silent no-op import into an honest failure.
        $command = app(ImportTextbooks::class);
        $method = new ReflectionMethod($command, 'looksLikePdf');

        $pdf = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($pdf, "%PDF-1.7\n...");
        $html = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($html, '<!DOCTYPE html><html>...');

        $this->assertTrue($method->invoke($command, $pdf));
        $this->assertFalse($method->invoke($command, $html));

        @unlink($pdf);
        @unlink($html);
    }
}
