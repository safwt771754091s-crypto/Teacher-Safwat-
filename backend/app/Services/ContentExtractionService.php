<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\LessonSection;
use App\Models\Textbook;
use App\Models\TextbookPage;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

/**
 * Extracts textbook content (text + page numbers) so the AI teacher can later
 * retrieve it instead of relying on model memory.
 */
class ContentExtractionService
{
    /** @var array<string,true> Lines repeated across the book (headers/footers). */
    private array $boilerplateLines = [];

    /**
     * Extract text page-by-page from a PDF and persist textbook_pages rows.
     * Tries the embedded text layer first; for scanned pages (no text layer) it
     * falls back to Arabic OCR when available. Returns ['page' => int, 'text' => string].
     */
    public function extractPdf(string $absolutePath, Textbook $textbook): array
    {
        // Many official editions are scanned images or owner-protected PDFs that
        // have no readable text layer. Parse the text layer when possible; on any
        // failure fall back to OCR-only, which rasterises the pages regardless.
        $textPages = [];
        try {
            $pdf = (new Parser())->parseFile($absolutePath);
            foreach ($pdf->getPages() as $i => $page) {
                $textPages[$i + 1] = trim($page->getText());
            }
        } catch (\Throwable $e) {
            $textPages = [];
        }

        $pageCount = max(count($textPages), $this->pdfPageCount($absolutePath));

        // A nonempty text layer is not necessarily usable: many official editions
        // embed subsetted fonts with a broken cmap, so getText() returns mojibake
        // ("≤ Z?UMLK UOKF«") that contains almost no Arabic/Latin letters. Count
        // only pages whose text is actually readable, and OCR when too few are.
        $readable = count(array_filter($textPages, fn ($t) => $this->looksReadable($t)));
        $needsOcr = $this->ocrAvailable()
            && ($pageCount === 0 || $readable < max(1, (int) floor($pageCount * 0.6)));

        $ocr = $needsOcr ? $this->ocrPdfPages($absolutePath) : [];

        if (empty($textPages) && empty($ocr)) {
            throw new \RuntimeException('No extractable text and OCR unavailable for: ' . basename($absolutePath));
        }

        $pageCount = max($pageCount, count($ocr));

        $result = [];
        for ($number = 1; $number <= $pageCount; $number++) {
            $layer = $textPages[$number] ?? '';
            // Prefer the text layer only when it is trustworthy; otherwise fall
            // back to OCR of the same page.
            $text = $this->looksReadable($layer)
                ? $this->normalizeArabic($layer)
                : $this->normalizeArabic($ocr[$number] ?? $layer);
            TextbookPage::updateOrCreate(
                ['textbook_id' => $textbook->id, 'page_number' => $number],
                ['extracted_text' => $text, 'status' => $text !== '' ? 'extracted' : 'raw'],
            );
            $result[] = ['page' => $number, 'text' => $text];
        }
        $textbook->update(['pages_count' => count($result), 'status' => 'review']);
        return $result;
    }

    private function pdfPageCount(string $absolutePath): int
    {
        if (! $this->binaryExists('pdfinfo')) {
            return 0;
        }
        $out = (string) @shell_exec('pdfinfo ' . escapeshellarg($absolutePath) . ' 2>/dev/null');
        if (preg_match('/^Pages:\s+(\d+)/mi', $out, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public function ocrAvailable(): bool
    {
        if (! config('platform.ocr.enabled')) {
            return false;
        }
        return $this->binaryExists(config('platform.ocr.tesseract')) && $this->binaryExists(config('platform.ocr.pdftoppm'));
    }

    private function binaryExists(?string $bin): bool
    {
        if (! $bin) {
            return false;
        }
        $path = trim((string) @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
        return $path !== '';
    }

    /**
     * Rasterise a PDF and OCR each page with tesseract (Arabic).
     *
     * @return array<int, string> page number => recognised text
     */
    private function ocrPdfPages(string $absolutePath): array
    {
        $out = [];
        $tmp = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(6));
        @mkdir($tmp, 0775, true);

        try {
            $dpi = (int) config('platform.ocr.dpi', 220);
            $pattern = $tmp . '/page';
            @shell_exec(sprintf(
                '%s -r %d -png %s %s 2>/dev/null',
                escapeshellarg(config('platform.ocr.pdftoppm')),
                $dpi,
                escapeshellarg($absolutePath),
                escapeshellarg($pattern),
            ));

            // tesseract is CPU bound, so a bounded worker pool uses spare cores
            // without thrashing; each worker pins to one thread (OMP_THREAD_LIMIT)
            // so N workers == N cores instead of N*4.
            $workers = max(1, (int) config('platform.ocr.workers', 2));
            $threads = max(1, (int) config('platform.ocr.threads', 1));
            $script = $tmp . '/run.sh';
            file_put_contents($script, sprintf(
                "#!/bin/sh\nimg=\"\$1\"\nexport OMP_THREAD_LIMIT=%d\n%s \"\$img\" \"out_\$(basename \"\$img\" .png)\" -l %s 2>/dev/null\n",
                $threads,
                config('platform.ocr.tesseract'),
                config('platform.ocr.language'),
            ));
            @shell_exec(sprintf(
                'cd %s && ls page*.png 2>/dev/null | xargs -r -P %d -n1 sh %s',
                escapeshellarg($tmp),
                $workers,
                escapeshellarg($script),
            ));

            foreach (glob($tmp . '/page*.png') ?: [] as $img) {
                if (! preg_match('/page-?(\d+)\.png$/', $img, $m)) {
                    continue;
                }
                $pageNo = (int) $m[1];
                $txt = $tmp . '/out_' . basename($img, '.png') . '.txt';
                $out[$pageNo] = is_file($txt) ? trim((string) file_get_contents($txt)) : '';
            }
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }

        return $out;
    }

    /**
     * OCR a single uploaded page image (best-effort; returns '' when unavailable).
     */
    public function ocrImage(string $absolutePath): string
    {
        if (! $this->binaryExists(config('platform.ocr.tesseract'))) {
            return '';
        }
        $text = (string) @shell_exec(sprintf(
            '%s %s stdout -l %s 2>/dev/null',
            escapeshellarg(config('platform.ocr.tesseract')),
            escapeshellarg($absolutePath),
            escapeshellarg(config('platform.ocr.language')),
        ));
        return $this->normalizeArabic(trim($text));
    }

    /**
     * Render one page of a textbook as a PNG image (bytes), for the in-app page
     * viewer. Prefers a stored page image; otherwise rasterises the page from
     * the book's source PDF (stored file or downloaded from its source URL).
     *
     * @return string|null PNG bytes, or null when the page cannot be produced.
     */
    public function pageImage(Textbook $textbook, int $pageNumber): ?string
    {
        $page = $textbook->pages()->where('page_number', $pageNumber)->first();

        // 1. A stored page image is already the source of record.
        if ($page && $page->image_path) {
            try {
                $disk = Storage::disk(config('platform.storage.textbook_disk', 'public'));
                if ($disk->exists($page->image_path)) {
                    return $this->asPng($disk->get($page->image_path));
                }
            } catch (\Throwable $e) {
                // fall through to PDF rendering
            }
        }

        // 2. Render from the book's PDF, then cache the PNG on the storage disk
        //    so later views (and other students) skip the download + rasterise.
        $png = $this->withTextbookPdf($textbook, fn (string $pdf) => $this->renderPdfPage($pdf, $pageNumber));
        if ($png === null) {
            return null;
        }

        $this->cachePageImage($textbook, $pageNumber, $png);

        return $png;
    }

    /**
     * Persist a rendered page image and remember its path on the page row.
     */
    private function cachePageImage(Textbook $textbook, int $pageNumber, string $png): void
    {
        try {
            $path = "textbooks/{$textbook->id}/pages/{$pageNumber}.png";
            $disk = Storage::disk(config('platform.storage.textbook_disk', 'public'));
            $disk->put($path, $png);

            TextbookPage::updateOrCreate(
                ['textbook_id' => $textbook->id, 'page_number' => $pageNumber],
                ['image_path' => $path],
            );
        } catch (\Throwable $e) {
            // Caching is best-effort; the rendered bytes are returned regardless.
        }
    }

    /**
     * Resolve the textbook's PDF to a local path and run the callback with it.
     * The file may live on the storage disk or be fetched from `source_url`;
     * any temporary copy is removed afterwards.
     */
    public function withTextbookPdf(Textbook $textbook, callable $fn)
    {
        if (! $this->binaryExists(config('platform.ocr.pdftoppm'))) {
            return null;
        }

        if ($textbook->file_path) {
            return $this->withLocalFile($textbook->file_path, $fn);
        }

        if (! $textbook->source_url) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'tbk_pdf_');
        try {
            @shell_exec(sprintf(
                'curl -sL --fail --max-time 120 -o %s %s 2>/dev/null',
                escapeshellarg($temp),
                escapeshellarg($textbook->source_url),
            ));
            if (! is_file($temp) || filesize($temp) < 1024) {
                return null;
            }

            return $fn($temp);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Rasterise a single PDF page to PNG bytes using pdftoppm.
     */
    private function renderPdfPage(string $pdfPath, int $pageNumber): ?string
    {
        $tmp = sys_get_temp_dir() . '/pg_' . bin2hex(random_bytes(6));
        try {
            @shell_exec(sprintf(
                '%s -f %d -l %d -r %d -png -singlefile %s %s 2>/dev/null',
                escapeshellarg(config('platform.ocr.pdftoppm')),
                $pageNumber,
                $pageNumber,
                (int) config('platform.page_image.dpi', 130),
                escapeshellarg($pdfPath),
                escapeshellarg($tmp),
            ));
            $png = $tmp . '.png';

            return is_file($png) ? (string) file_get_contents($png) : null;
        } finally {
            if (is_file($tmp . '.png')) {
                @unlink($tmp . '.png');
            }
        }
    }

    /**
     * Normalise arbitrary image bytes to PNG (the viewer requests PNG).
     */
    private function asPng(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }
        ob_start();
        imagepng($img, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    /**
     * Resolve a stored file to a real local path, downloading it to a temp file
     * when the disk is remote (e.g. Supabase/S3). The callback receives the path;
     * any temp copy is always removed afterwards.
     */
    public function withLocalFile(string $storedPath, callable $fn)
    {
        $disk = Storage::disk(config('platform.storage.textbook_disk', 'public'));
        $temp = null;

        try {
            $path = $disk->path($storedPath);
        } catch (\Throwable $e) {
            $path = '';
        }

        if ($path === '' || ! is_file($path)) {
            $temp = tempnam(sys_get_temp_dir(), 'tbk_');
            file_put_contents($temp, (string) $disk->get($storedPath));
            $path = $temp;
        }

        try {
            return $fn($path);
        } finally {
            if ($temp && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Store an uploaded page image. When no text is supplied, Arabic OCR is
     * attempted; regardless, the image is preserved as the source of record so
     * no content is guessed and a human can always confirm or correct it.
     */
    public function storeImagePage(Textbook $textbook, int $pageNumber, string $imagePath, ?string $text = null): TextbookPage
    {
        if ($text === null || $text === '') {
            $text = $this->withLocalFile($imagePath, fn ($absolute) => $this->ocrImage($absolute)) ?: null;
        }

        return TextbookPage::updateOrCreate(
            ['textbook_id' => $textbook->id, 'page_number' => $pageNumber],
            [
                'image_path' => $imagePath,
                'extracted_text' => $text,
                'status' => $text ? 'extracted' : 'raw',
            ],
        );
    }

    /**
     * Segment a textbook into lessons by its own headings. Yemeni editions label
     * each lesson "الدرس ..." (with variants like "الدرس الأول" or OCR slips such
     * as "الدرس الغالت"); units are "الوحدة ..." and chapters "الفصل ...". A
     * lesson runs from its heading page to the page before the next heading.
     *
     * Idempotent: previous draft lessons for the book are replaced, so a re-run
     * (e.g. after OCR improves) never duplicates content. Everything stays in
     * 'draft' for human review.
     */
    public function draftLessonsFromPages(Textbook $textbook): int
    {
        // Never overwrite curated lessons (authored/approved content). Only books
        // with no published lessons are segmented from their page text.
        if ($textbook->lessons()->where('status', 'published')->exists()) {
            return 0;
        }

        $pages = $textbook->pages()->orderBy('page_number')
            ->get()
            ->filter(fn ($p) => $this->looksReadable((string) $p->extracted_text));

        $this->boilerplateLines = $this->detectBoilerplate($pages);

        $lessons = [];   // list of ['title','page_from','page_to','sections'=>[]]
        $current = null; // active lesson index

        foreach ($pages as $page) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $page->extracted_text);
            $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));

            // A contents/index page lists many lesson titles in a row; treating
            // it as lesson starts would create dozens of empty lessons. Skip it.
            $headingCount = count(array_filter($lines, fn ($l) => $this->isLessonHeading($l)));
            if ($headingCount >= 3) {
                continue;
            }

            foreach ($lines as $line) {
                if ($this->isUnitHeading($line) || $this->isActivityHeading($line)) {
                    continue; // unit/activity banners are section context, not lessons
                }
                if ($this->isLessonHeading($line)) {
                    $title = $this->cleanHeading($line);
                    // Ignore a repeated running header instead of forking a
                    // second lesson with the same title.
                    if ($current !== null && $this->sameHeading($title, $lessons[$current]['title'])) {
                        continue;
                    }
                    $lessons[] = [
                        'title' => $title,
                        'page_from' => $page->page_number,
                        'page_to' => $page->page_number,
                        'sections' => [],
                    ];
                    $current = count($lessons) - 1;
                    continue;
                }
                if ($current === null) {
                    continue; // front matter (contents, unit intro) is not a lesson
                }
                if ($this->isNoiseLine($line)) {
                    continue;
                }
                $lessons[$current]['page_to'] = $page->page_number;
                $lessons[$current]['sections'][] = [
                    'type' => $this->guessSectionType($line),
                    'content' => $line,
                    'source_page' => $page->page_number,
                ];
            }
        }

        // Drop heading-only fragments: an empty lesson is a stray heading, not a
        // lesson with content.
        $lessons = array_values(array_filter($lessons, fn ($l) => ! empty($l['sections'])));

        // Books with no "الدرس" (e.g. primary maths) still mark الوحدة/الفصل
        // sections; group pages under those so the book stays navigable rather
        // than becoming one meaningless lesson per page.
        if (count($lessons) < 4) {
            $units = $this->segmentByUnit($pages);
            if (count($units) > count($lessons)) {
                $lessons = $units;
            }
        }

        // When heading detection failed, the book collapses into a handful of
        // giant lessons. A real lesson runs a few pages, so averaging >20 pages
        // per lesson means the structure was missed. Fall back to page ranges -
        // never invented titles - which keeps the book navigable.
        $pageCount = $pages->count();
        if ($pageCount >= 20 && count($lessons) < max(2, (int) floor($pageCount / 20))) {
            $chunks = $this->chunkByPages($pages, 6);
            if (count($chunks) > count($lessons)) {
                $lessons = $chunks;
            }
        }

        $textbook->lessons()->delete();

        $created = 0;
        foreach ($lessons as $i => $data) {
            $lesson = Lesson::create([
                'textbook_id' => $textbook->id,
                'title' => $data['title'],
                'page_from' => $data['page_from'],
                'page_to' => $data['page_to'],
                'sort_order' => $i,
                'status' => 'draft',
            ]);
            $order = 0;
            foreach ($data['sections'] as $section) {
                LessonSection::create([
                    'lesson_id' => $lesson->id,
                    'type' => $section['type'],
                    'content' => $section['content'],
                    'source_page' => $section['source_page'],
                    'sort_order' => $order++,
                    'is_from_textbook' => true,
                ]);
            }
            $created++;
        }
        return $created;
    }

    private function isLessonHeading(string $line): bool
    {
        return (bool) preg_match('/^الدرس(?=\s|[:،؛]|$)/u', $line);
    }

    /**
     * Tidy an OCR heading. OCR glues page-leader debris onto headings
     * ("الوحدة السابعة: مممم ... 60060000000"); cut at the first long digit run
     * or run of repeated characters, then strip trailing punctuation.
     */
    public function tidyHeading(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/\d{5,}.*$/u', '', $line) ?? $line;
        $line = preg_replace('/(.)\1{4,}.*$/u', '', $line) ?? $line;

        // Peel trailing OCR garbage tokens ("ممم", "ـــ", bare digits) that
        // cling to the end of a heading.
        $parts = preg_split('/\s+/u', $line) ?: [];
        while (! empty($parts) && $this->isJunkToken((string) end($parts))) {
            array_pop($parts);
        }
        $line = implode(' ', $parts);
        $line = preg_replace('/[\s.:،؛\x{0640}]+$/u', '', $line) ?? $line;

        return mb_substr(trim((string) $line), 0, 120);
    }

    /** A token is OCR debris when it is <2 chars, has no Arabic letter, or is a single character repeated. */
    private function isJunkToken(string $token): bool
    {
        if (mb_strlen($token) < 2) {
            return true;
        }
        if (! preg_match('/[\x{0621}-\x{064A}]/u', $token)) {
            return true;
        }
        $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count(array_unique($chars)) === 1;
    }

    private function cleanHeading(string $line): string
    {
        return $this->tidyHeading($line);
    }

    /**
     * Two headings name the same group when their leading words match (OCR
     * differs only in trailing digits/noise). Folds a repeated running header
     * into the current group instead of starting a new one.
     */
    private function sameHeading(string $a, string $b): bool
    {
        $a = trim(preg_replace('/\s+/u', ' ', $a) ?? $a);
        $b = trim(preg_replace('/\s+/u', ' ', $b) ?? $b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return mb_substr($a, 0, 10) === mb_substr($b, 0, 10);
    }

    private function isUnitHeading(string $line): bool
    {
        return (bool) preg_match('/^(الوحدة|الموضوع|المحور|الفصل)(?=\s|[:،؛]|$)/u', $line);
    }

    /**
     * Primary editions mark each activity with a "نشاط ..." banner; treat it as
     * a group heading so a book without per-lesson headings stays navigable.
     */
    private function isActivityHeading(string $line): bool
    {
        return (bool) preg_match('/^نشاط(?=\s|[:،؛]|$)/u', $line);
    }

    /**
     * Drop OCR debris and repeated running headers/footers so they neither
     * become a lesson's content nor masquerade as a heading: URL/email footers,
     * too-short lines, and lines with no Arabic letter (garbled glyph runs).
     */
    private function isNoiseLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return true;
        }
        if (isset($this->boilerplateLines[$line])) {
            return true;
        }
        if (preg_match('/(www\.|\.ye\b|edu\.|@)/iu', $line)) {
            return true;
        }
        if (mb_strlen($line) < 3) {
            return true;
        }

        return ! preg_match('/[\x{0621}-\x{064A}]/u', $line);
    }

    /**
     * Lines repeated across many pages (running headers/footers, publisher and
     * site furniture) carry no lesson content. Treat a line seen on >=8 pages
     * and >=30% of the book as boilerplate.
     *
     * @param  \Illuminate\Support\Collection<int,TextbookPage>  $pages
     * @return array<string,true>
     */
    private function detectBoilerplate($pages): array
    {
        $counts = [];
        foreach ($pages as $page) {
            $seen = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) $page->extracted_text) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $seen[$line] = true;
                }
            }
            foreach (array_keys($seen) as $line) {
                $counts[$line] = ($counts[$line] ?? 0) + 1;
            }
        }

        $threshold = max(8, (int) floor(max(1, $pages->count()) * 0.3));
        $boilerplate = [];
        foreach ($counts as $line => $n) {
            if ($n >= $threshold) {
                $boilerplate[$line] = true;
            }
        }

        return $boilerplate;
    }

    /**
     * Last-resort segmentation for books with no usable headings: split the
     * pages into fixed-size ranges titled only by their page span. No title is
     * invented; the range is the honest structure the text provides.
     *
     * @param  \Illuminate\Support\Collection<int,TextbookPage>  $pages
     * @return list<array{title:string,page_from:int,page_to:int,sections:list<array{type:string,content:string,source_page:int}>}>
     */
    private function chunkByPages($pages, int $size): array
    {
        $chunks = [];
        $buffer = [];
        $from = null;
        $to = null;

        $flush = function () use (&$chunks, &$buffer, &$from, &$to) {
            if (empty($buffer)) {
                return;
            }
            $chunks[] = [
                'title' => "صفحات {$from} - {$to}",
                'page_from' => $from,
                'page_to' => $to,
                'sections' => $buffer,
            ];
            $buffer = [];
            $from = null;
            $to = null;
        };

        foreach ($pages as $page) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $page->extracted_text))));
            $lines = array_values(array_filter(
                $lines,
                fn ($l) => ! $this->isNoiseLine($l) && ! $this->isUnitHeading($l) && ! $this->isActivityHeading($l),
            ));
            if (empty($lines)) {
                continue;
            }
            $from = $from ?? $page->page_number;
            $to = $page->page_number;
            foreach ($lines as $line) {
                $buffer[] = [
                    'type' => $this->guessSectionType($line),
                    'content' => $line,
                    'source_page' => $page->page_number,
                ];
            }
            if (($to - $from) >= $size) {
                $flush();
            }
        }
        $flush();

        return $chunks;
    }

    /**
     * Group pages under الوحدة/الفصل headings for books without per-lesson
     * headings. Pages before the first heading form a "مقدمة" lesson; an orphan
     * heading with no following pages is dropped.
     *
     * @param  \Illuminate\Support\Collection<int,TextbookPage>  $pages
     * @return list<array{title:string,page_from:int,page_to:int,sections:list<array{type:string,content:string,source_page:int}>}>
     */
    private function segmentByUnit($pages): array
    {
        $units = []; // list of ['title','page_from','page_to','sections']
        $current = null;

        foreach ($pages as $page) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $page->extracted_text))));
            $headings = array_values(array_filter(
                $lines,
                fn ($l) => $this->isUnitHeading($l) || $this->isActivityHeading($l),
            ));

            // A contents page lists several units at once; skip it so it cannot
            // masquerade as a unit with no content.
            if (count($headings) >= 3) {
                continue;
            }

            $heading = $headings[0] ?? null;
            $body = array_values(array_filter(
                $lines,
                fn ($l) => $heading === null || $l !== $heading,
            ));

            if ($heading !== null) {
                $title = $this->cleanHeading($heading);
                // A heading repeated on consecutive pages is a running header,
                // not a new group; continue the current one instead of creating
                // a stream of identically-titled lessons.
                if ($current === null || ! $this->sameHeading($title, $units[$current]['title'])) {
                    $units[] = [
                        'title' => $title,
                        'page_from' => $page->page_number,
                        'page_to' => $page->page_number,
                        'sections' => [],
                    ];
                    $current = count($units) - 1;
                }
            } elseif ($current === null) {
                $units[] = [
                    'title' => 'مقدمة الكتاب',
                    'page_from' => $page->page_number,
                    'page_to' => $page->page_number,
                    'sections' => [],
                ];
                $current = count($units) - 1;
            }

            $units[$current]['page_to'] = $page->page_number;
            foreach ($body as $line) {
                if ($this->isNoiseLine($line)) {
                    continue;
                }
                $units[$current]['sections'][] = [
                    'type' => $this->guessSectionType($line),
                    'content' => $line,
                    'source_page' => $page->page_number,
                ];
            }
        }

        return array_values(array_filter($units, fn ($u) => ! empty($u['sections'])));
    }

    /**
     * Surface the textbook's own exercise questions as draft exercises on each
     * lesson. Only question text that is actually present in the page becomes a
     * row; the answer is left empty and `verified_at` stays null so a supervisor
     * must supply/verify it before publishing. Guarded against OCR noise: lines
     * must be short Arabic prose containing a letter.
     *
     * Idempotent: existing draft exercises for the lesson are replaced, so
     * improved OCR can be re-run without duplicating rows.
     */
    public function draftExercisesFromLesson(Lesson $lesson): int
    {
        $lesson->loadMissing('sections');

        // Gather the lesson's lines in reading order, keeping the source page so
        // every surfaced question stays traceable to the textbook.
        $lines = [];
        foreach ($lesson->sections as $section) {
            foreach (preg_split('/\r\n|\r|\n/', (string) $section->content) as $raw) {
                $lines[] = ['text' => trim($raw), 'page' => $section->source_page];
            }
        }

        $questions = $this->extractQuestionBlocks($lines);

        if (empty($questions)) {
            return 0;
        }

        // Only replace drafts this extractor created. Authored/teacher exercises
        // (is_draft = false) are never touched.
        $lesson->exercises()->where('is_draft', true)->delete();

        $created = 0;
        foreach ($questions as $i => $q) {
            $exercise = Exercise::create([
                'lesson_id' => $lesson->id,
                'type' => $q['type'],
                'question' => $q['question'],
                'answer' => null,
                'source_page' => $q['page'],
                'sort_order' => $i,
                'source' => 'textbook',
                'is_draft' => true,
            ]);
            foreach ($q['options'] as $j => $text) {
                $exercise->options()->create([
                    'label' => $this->optionLabel($text),
                    'text' => $text,
                    'is_correct' => false,
                    'sort_order' => $j,
                ]);
            }
            $created++;
        }
        return $created;
    }

    /**
     * Pull the textbook's own questions out of a lesson's OCR lines.
     *
     * Two shapes are recognised, both kept as *drafts* for a human to answer:
     *  - an instruction line ("أجب عن الأسئلة الآتية:") starts a block; the
     *    numbered questions that follow it are the real exercises.
     *  - a standalone numbered question line (with or without a keyword).
     * Multiple-choice options ("أ- ..." / "ب - ...") attach to the last question.
     *
     * @param  list<array{text:string,page:?int}>  $lines
     * @return list<array{question:string,type:string,page:?int,options:list<string>}>
     */
    private function extractQuestionBlocks(array $lines): array
    {
        $isInstruction = fn (string $t): bool => (bool) preg_match(
            '/^(?:تمرين|تدريب|تدريبات|أسئلة|أسئله|أنشطة|نشاط|أجب|أكمل|اختر|صل|حل|علل|استنتج|اكتب|رتب|ضع|عدّ|عُدّ)\b/u',
            $t,
        );
        // A keyword line with no digits/equals/question mark is just a section
        // header ("أجب عن الأسئلة الآتية:"); with content ("تمرين: اجمع ١+٢")
        // it is itself the question.
        $hasContent = fn (string $t): bool => (bool) preg_match('/[0-9\x{0660}-\x{0669}?؟=]/u', $t);
        // Numbered question: leading Arabic/Latin digit (e.g. "١-", "3.", "٤ )").
        $isNumbered = fn (string $t): bool => (bool) preg_match('/^[0-9\x{0660}-\x{0669}]+\s*[-).:\x{066B}\x{066C}]/u', $t);
        $isOption = fn (string $t): bool => (bool) preg_match('/^(?:[أبجدهوزABCD])\s*[-–)]/u', $t);
        $isNoise = fn (string $t): bool => mb_strlen($t) < 3
            || mb_strlen($t) > 140
            || ! preg_match('/[\p{Arabic}]/u', $t)
            || (bool) preg_match('/https?:\/\/|www\./iu', $t);

        $questions = [];
        $seen = [];
        $inBlock = false;
        $current = -1;

        foreach ($lines as $line) {
            $text = $line['text'];
            if ($text === '') {
                continue;
            }

            if ($isInstruction($text) && mb_strlen($text) <= 60 && ! $hasContent($text)) {
                // A bare section header — the real questions follow it.
                $inBlock = true;
                $current = -1;
                continue;
            }

            if ($isInstruction($text) && ! $isNoise($text)) {
                // A keyword line that carries the actual task ("تمرين: اجمع ١+٢").
                if (! isset($seen[$text])) {
                    $seen[$text] = true;
                    $questions[] = [
                        'question' => $text,
                        'type' => $this->guessExerciseType($text),
                        'page' => $line['page'],
                        'options' => [],
                    ];
                    $current = count($questions) - 1;
                }
                $inBlock = true;
                continue;
            }

            if ($isNumbered($text)) {
                $body = trim(preg_replace('/^[0-9\x{0660}-\x{0669}]+\s*[-).:\x{066B}\x{066C}]+\s*/u', '', $text));
                if ($isNoise($body)) {
                    continue;
                }
                // A numbered line only counts as a question when we are inside an
                // exercise block or the line itself asks something.
                $asks = (bool) preg_match('/(?:\?|؟|ما|ماذا|كيف|لماذا|اذكر|عدد|بيّن|وضح|أكمل|اختر|أجب)/u', $body);
                if (! $inBlock && ! $asks) {
                    continue;
                }
                if (isset($seen[$body])) {
                    continue;
                }
                $seen[$body] = true;
                $questions[] = [
                    'question' => $body,
                    'type' => $this->guessExerciseType($body),
                    'page' => $line['page'],
                    'options' => [],
                ];
                $current = count($questions) - 1;
                continue;
            }

            if ($isOption($text) && $current >= 0) {
                $questions[$current]['options'][] = trim($text);
                if ($questions[$current]['type'] === 'short_answer') {
                    $questions[$current]['type'] = 'mcq';
                }
            }
        }

        return $questions;
    }

    private function optionLabel(string $text): ?string
    {
        return preg_match('/^\s*([أبجدهوزABCD])/u', $text, $m) ? $m[1] : null;
    }

    private function guessExerciseType(string $question): string
    {
        if (preg_match('/(?:اختر|أي من|الصحيح)/u', $question)) {
            return 'mcq';
        }
        if (preg_match('/(?:صح|خطأ|صواب|هل )/u', $question)) {
            return 'true_false';
        }

        return 'short_answer';
    }

    private function guessSectionType(string $line): string
    {
        if (preg_match('/^(تمرين|سؤال|أجب|حل)/u', $line)) {
            return 'example';
        }
        if (preg_match('/^(تعريف|المقصود)/u', $line)) {
            return 'definition';
        }
        if (preg_match('/^(ملاحظة|لاحظ|تذكر)/u', $line)) {
            return 'note';
        }
        return 'point';
    }

    public function normalizeArabic(?string $text): string
    {
        $text = (string) $text;
        $text = str_replace(["\u{200B}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim((string) $text);
    }

    /**
     * Decide whether extracted text is genuine reading material or font-encoding
     * mojibake. Digital editions with a broken cmap yield long runs of exotic
     * glyphs and almost no letters; OCR output always contains real letters, so
     * this drives the "text layer vs OCR" choice without hardcoding any book.
     */
    public function textIsReadable(string $text): bool
    {
        return $this->looksReadable($text);
    }

    private function looksReadable(string $text): bool
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return false;
        }

        // Scanned editions often carry ONLY a footer (site URL, phone, Telegram
        // link) as their text layer. That is boilerplate, not content, and must
        // not mark the page readable - otherwise OCR of the real page body is
        // skipped and the book looks empty. Strip those tokens and require
        // something substantive to remain.
        if (preg_match('/(https?:\/\/|www\.|t\.me\/|\S+@\S+|\.ye\b)/iu', $text)) {
            $stripped = preg_replace('#(https?://\S+|www\.\S+|t\.me/\S+|\S+@\S+)#iu', ' ', $text) ?? $text;
            $stripped = preg_replace('/[+\d\s\-_.|،؛:]+/u', ' ', $stripped) ?? $stripped;
            if (mb_strlen(trim($stripped)) < 10) {
                return false;
            }
        }

        // Genuine prose is dominated by Arabic letters or lowercase Latin (plus
        // digits on maths pages). Broken-cmap output is instead uppercase-heavy
        // with almost no lowercase, so excluding uppercase separates the two.
        $len = mb_strlen($text);
        $meaningful = preg_match_all('/[\x{0600}-\x{06FF}a-z0-9]/u', $text);
        return ($meaningful / $len) >= 0.35;
    }
}
