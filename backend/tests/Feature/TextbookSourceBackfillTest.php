<?php

namespace Tests\Feature;

use App\Models\Textbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextbookSourceBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_it_backfills_source_url_from_a_manifest(): void
    {
        $book = Textbook::whereNull('source_url')
            ->whereHas('subject.grade', fn ($q) => $q->where('level', 1))
            ->firstOrFail();

        $manifest = $this->manifest([[
            'title' => $book->title,
            'url' => 'https://s1.yemen-books.com/files/books/1st/math.pdf',
            'grade_level' => 1,
            'subject' => $book->subject->name,
            'term' => $book->term,
        ]]);

        $this->artisan('textbooks:backfill-sources', ['manifest' => $manifest])->assertSuccessful();

        $this->assertSame(
            'https://s1.yemen-books.com/files/books/1st/math.pdf',
            $book->fresh()->source_url,
        );
    }

    public function test_it_matches_by_grade_and_subject_when_the_title_differs(): void
    {
        $book = Textbook::whereNull('source_url')
            ->whereHas('subject.grade', fn ($q) => $q->where('level', 1))
            ->firstOrFail();

        $manifest = $this->manifest([[
            'title' => 'اسم مختلف تمامًا',
            'url' => 'https://s1.yemen-books.com/files/books/1st/matched-by-key.pdf',
            'grade_level' => 1,
            'subject' => $book->subject->name,
            'term' => $book->term,
        ]]);

        $this->artisan('textbooks:backfill-sources', ['manifest' => $manifest])->assertSuccessful();

        $this->assertSame(
            'https://s1.yemen-books.com/files/books/1st/matched-by-key.pdf',
            $book->fresh()->source_url,
        );
    }

    public function test_it_never_overwrites_an_existing_source_url(): void
    {
        $book = Textbook::firstOrFail();
        $book->forceFill(['source_url' => 'https://example.test/keep-me.pdf'])->save();

        $manifest = $this->manifest([[
            'title' => $book->title,
            'url' => 'https://example.test/should-not-replace.pdf',
            'grade_level' => (int) $book->subject->grade->level,
            'subject' => $book->subject->name,
            'term' => $book->term,
        ]]);

        $this->artisan('textbooks:backfill-sources', ['manifest' => $manifest])->assertSuccessful();

        $this->assertSame('https://example.test/keep-me.pdf', $book->fresh()->source_url);
    }

    /** @param list<array<string, mixed>> $entries */
    private function manifest(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest_') . '.json';
        file_put_contents($path, json_encode($entries, JSON_UNESCAPED_UNICODE));
        $this->tempManifests[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $tempManifests = [];

    protected function tearDown(): void
    {
        foreach ($this->tempManifests as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }
}
