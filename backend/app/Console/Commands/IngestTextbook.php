<?php

namespace App\Console\Commands;

use App\Models\Textbook;
use App\Services\ContentExtractionService;
use Illuminate\Console\Command;

class IngestTextbook extends Command
{
    protected $signature = 'textbooks:ingest
        {textbook : Textbook id}
        {path : Absolute path to a PDF file}
        {--draft-lessons : Also draft lessons from the extracted pages}';

    protected $description = 'Extract (with Arabic OCR when needed) a textbook PDF into pages';

    public function handle(ContentExtractionService $extractor): int
    {
        $textbook = Textbook::findOrFail((int) $this->argument('textbook'));
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $this->info('OCR: ' . ($extractor->ocrAvailable() ? 'enabled (tesseract)' : 'unavailable (text layer only)'));
        $this->info('Extracting ' . basename($path) . ' ...');

        $pages = $extractor->extractPdf($path, $textbook);
        $withText = collect($pages)->filter(fn ($p) => $p['text'] !== '')->count();
        $this->info('Extracted ' . count($pages) . " pages ({$withText} with text).");

        if ($this->option('draft-lessons')) {
            $created = $extractor->draftLessonsFromPages($textbook);
            $this->info("Drafted {$created} lessons.");
        }

        return self::SUCCESS;
    }
}
