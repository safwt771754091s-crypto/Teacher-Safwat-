<?php

namespace App\Console\Commands;

use App\Models\Textbook;
use App\Services\ContentExtractionService;
use Illuminate\Console\Command;

/**
 * Re-run lesson segmentation on books already in the database, using their
 * stored page text. Needed after the segmenter improves (or after a re-OCR)
 * because segmentation is derived, not stored on the book.
 */
class ResegmentTextbooks extends Command
{
    protected $signature = 'textbooks:resegment
        {--grade= : Only books of this grade level}
        {--textbook= : Only this textbook id}
        {--only-empty : Only books with fewer than 4 lessons}';

    protected $description = 'Re-segment stored textbook pages into draft lessons';

    public function handle(ContentExtractionService $extractor): int
    {
        $query = Textbook::query()->whereHas('pages');

        if ($id = $this->option('textbook')) {
            $query->where('id', (int) $id);
        }
        if ($grade = $this->option('grade')) {
            $query->whereHas('subject', fn ($q) => $q->whereHas('grade', fn ($g) => $g->where('level', (int) $grade)));
        }

        $books = $query->get();
        $total = 0;
        foreach ($books as $book) {
            if ($this->option('only-empty') && $book->lessons()->count() >= 4) {
                continue;
            }
            $created = $extractor->draftLessonsFromPages($book);
            $total += $created;
            $this->line("  [{$book->id}] {$created} lessons  " . mb_substr($book->title, 0, 40));
        }

        $this->info("Segmented {$books->count()} book(s) into {$total} lessons.");

        return self::SUCCESS;
    }
}
