<?php

namespace App\Services;

use App\Models\Textbook;
use Illuminate\Support\Facades\Artisan;

/**
 * Server-side driver for bulk textbook ingestion.
 *
 * The books are large PDFs on third-party hosts; downloading and OCR-ing one can
 * take tens of seconds, so a single web request cannot ingest the whole
 * catalogue. This service runs the existing `textbooks:import` command in
 * *bounded batches*: each call imports as many books as fit in a short wall-clock
 * budget and reports where to resume, so the owner console can loop until the
 * catalogue is covered without ever holding a request open indefinitely.
 */
class TextbookImportService
{
    /** Manifests an owner may import from, keyed by a stable short name. */
    public const MANIFESTS = [
        'all' => 'database/data/yemen_books_all.json',
        'aden' => 'database/data/yemen_books_aden.json',
        'sanaa' => 'database/data/yemen_books.json',
    ];

    /** The manifest matching the curriculum edition the platform adopts. */
    public static function defaultManifest(): string
    {
        return match (config('platform.curriculum.authority')) {
            'aden' => 'aden',
            'sanaa' => 'sanaa',
            default => 'all',
        };
    }

    /**
     * Run one bounded batch and return the command's JSON summary.
     *
     * @return array{ok:bool,imported?:int,skipped?:int,failed?:int,next_offset?:int,consumed?:int,timed_out?:bool,error?:string}
     */
    public function runBatch(string $manifestKey, int $offset, int $maxSeconds, ?int $grade = null, bool $force = false): array
    {
        $rel = self::MANIFESTS[$manifestKey] ?? self::MANIFESTS[self::defaultManifest()];
        $path = base_path($rel);
        if (! is_file($path)) {
            return ['ok' => false, 'error' => "Manifest not found: {$rel}"];
        }

        $options = [
            'manifest' => $path,
            '--offset' => max(0, $offset),
            '--max-seconds' => max(5, min(120, $maxSeconds)),
            '--json' => true,
        ];
        if ($grade) {
            $options['--grade'] = $grade;
        }
        if ($force) {
            $options['--force'] = true;
        }

        Artisan::call('textbooks:import', $options);
        $out = trim(Artisan::output());
        $decoded = json_decode($out, true);
        if (! is_array($decoded)) {
            return ['ok' => false, 'error' => 'Unexpected output: '.mb_substr($out, 0, 300)];
        }

        return $decoded;
    }

    /**
     * Per-book readiness: how many pages were stored and how many hold readable
     * text, so the console can show real progress and skip finished books.
     *
     * @return array{books:int,ready:int,pending:int,items:list<array<string,mixed>>}
     */
    public function coverage(?int $grade = null): array
    {
        $query = Textbook::query()->withCount([
            'pages as stored_pages',
            'pages as readable_pages' => fn ($q) => $q->whereRaw('LENGTH(extracted_text) > 200'),
        ])->orderBy('id');

        if ($grade) {
            $query->whereHas('subject.grade', fn ($q) => $q->where('level', $grade));
        }

        $items = [];
        $ready = 0;
        foreach ($query->get() as $book) {
            $stored = (int) $book->stored_pages;
            $readable = (int) $book->readable_pages;
            $isReady = $stored > 0 && $readable >= max(1, (int) ceil($stored * 0.6));
            if ($isReady) {
                $ready++;
            }
            $items[] = [
                'id' => $book->id,
                'title' => $book->title,
                'pages_count' => (int) $book->pages_count,
                'stored_pages' => $stored,
                'readable_pages' => $readable,
                'ready' => $isReady,
            ];
        }

        return [
            'books' => count($items),
            'ready' => $ready,
            'pending' => count($items) - $ready,
            'items' => $items,
        ];
    }
}
