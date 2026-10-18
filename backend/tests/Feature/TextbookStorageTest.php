<?php

namespace Tests\Feature;

use App\Models\Textbook;
use App\Models\User;
use App\Services\ContentExtractionService;
use App\Services\TextbookExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifies the whole-book pipeline: a PDF is stored on the configured disk, its
 * pages are extracted, and the download (which embeds the book's own pages)
 * includes them. Also checks that remote (Supabase-style) disks resolve files
 * through a temp copy instead of the local-only path().
 */
class TextbookStorageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function owner(): User
    {
        return User::where('email', 'owner@school.ye')->first();
    }

    public function test_pdf_upload_stores_and_extracts_pages(): void
    {
        Storage::fake('public');
        $book = Textbook::whereHas('lessons')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent('book.pdf', $this->samplePdf());

        $this->actingAs($this->owner(), 'sanctum')
            ->post("/api/v1/textbooks/{$book->id}/upload-pdf", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('pages_extracted', 2);

        $page = $book->pages()->where('page_number', 1)->first();
        $this->assertNotNull($page);
        $this->assertSame('extracted', $page->status);
        $this->assertNotSame('', trim((string) $page->extracted_text));
    }

    public function test_download_embeds_the_uploaded_book_pages(): void
    {
        Storage::fake('public');
        $book = Textbook::whereHas('lessons')->firstOrFail();
        $file = UploadedFile::fake()->createWithContent('book.pdf', $this->samplePdf());

        $this->actingAs($this->owner(), 'sanctum')
            ->post("/api/v1/textbooks/{$book->id}/upload-pdf", ['file' => $file])->assertOk();

        $html = $this->get("/api/v1/textbooks/{$book->id}/download?format=html")->assertOk()->getContent();
        $this->assertStringContainsString('نصّ الكتاب', $html);
        $this->assertStringContainsString('class="page"', $html);

        // `pages=0` returns the explanation/exercises only.
        $without = $this->get("/api/v1/textbooks/{$book->id}/download?format=html&pages=0")->assertOk()->getContent();
        $this->assertStringNotContainsString('class="page"', $without);
    }

    public function test_remote_disk_resolves_via_temp_copy(): void
    {
        // A remote disk (Supabase/S3) has no local path(): the service must pull
        // the file into a temp copy, use it, then delete it.
        $pdf = $this->samplePdf();
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('path')->andThrow(new \RuntimeException('This driver does not support retrieving local file paths.'));
        $disk->shouldReceive('get')->with('textbook-pages/1.pdf')->andReturn($pdf);
        Storage::shouldReceive('disk')->andReturn($disk);

        $seen = null;
        app(ContentExtractionService::class)->withLocalFile('textbook-pages/1.pdf', function ($path) use (&$seen, $pdf) {
            $seen = $path;
            $this->assertFileExists($path);
            $this->assertSame($pdf, file_get_contents($path));

            return 'ok';
        });

        $this->assertNotNull($seen);
        $this->assertFileDoesNotExist($seen);
    }

    /** A real two-page PDF with a text layer, produced by the app's own renderer. */
    private function samplePdf(): string
    {
        $html = '<h1>الدرس الأول : الجمع</h1><p>نتعلم الجمع حتى عشرة</p>'
            . '<pagebreak /><h2>أمثلة على الجمع</h2><p>٢ + ٣ = ٥</p>';

        return app(TextbookExportService::class)->pdf($html);
    }

    public function test_source_url_is_trimmed_on_save(): void
    {
        $book = Textbook::whereHas('lessons')->firstOrFail();
        $book->source_url = "  https://example.test/book.pdf\n";
        $book->save();

        $this->assertSame('https://example.test/book.pdf', $book->fresh()->source_url);
    }

    public function test_manual_book_without_pdf_renders_a_text_page_image(): void
    {
        // Books with no source PDF (catalogue-only "manual" rows) must still
        // produce a page image from their stored text instead of failing.
        if (trim((string) @shell_exec('command -v pdftoppm 2>/dev/null')) === '') {
            $this->markTestSkipped('pdftoppm is not installed.');
        }

        $book = Textbook::create([
            'subject_id' => Textbook::whereHas('lessons')->firstOrFail()->subject_id,
            'title' => 'كتاب نصي', 'term' => 'first', 'source_type' => 'manual',
            'file_path' => null, 'source_url' => null, 'status' => 'published',
        ]);
        $book->pages()->create([
            'page_number' => 1,
            'extracted_text' => 'درس: أسرتي وبيتي. نتعرف في هذا الدرس على أفراد الأسرة.',
            'status' => 'extracted',
        ]);

        $png = app(ContentExtractionService::class)->pageImage($book, 1);

        $this->assertNotNull($png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }
}
