<?php

namespace App\Console\Commands;

use App\Models\Textbook;
use App\Services\ContentExtractionService;
use Illuminate\Console\Command;

/**
 * Segment imported textbooks into draft lessons from their own headings. Run
 * after textbooks:import (and again after OCR improves a book). Idempotent: a
 * book is skipped when it already has lessons unless --force is given.
 */
class SegmentTextbooks extends Command
{
    protected $signature = 'textbooks:segment
        {--grade= : Only this grade level}
        {--authority= : Only this authority (common|sanaa|aden)}
        {--force : Re-segment books that already have lessons}';

    protected $description = 'Build draft lessons from an imported textbook\'s own headings';

    public function handle(ContentExtractionService $extractor): int
    {
        $query = Textbook::query()->has('pages');

        if ($grade = $this->option('grade')) {
            $query->whereHas('subject', fn ($q) => $q->where('grade_id', (int) $grade));
        }
        if ($authority = $this->option('authority')) {
            $query->where('authority', $authority);
        }
        if (! $this->option('force')) {
            $query->doesntHave('lessons');
        }

        $books = $query->get();
        $this->info("Segmenting {$books->count()} textbook(s)…");

        $total = 0;
        foreach ($books as $book) {
            $created = $extractor->draftLessonsFromPages($book);
            $total += $created;
            $this->line(sprintf('  %s → %d lessons', $book->title, $created));
        }

        $this->info("Drafted {$total} lesson(s) across {$books->count()} book(s).");
        return self::SUCCESS;
    }
}
