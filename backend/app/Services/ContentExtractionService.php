<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonSection;
use App\Models\Textbook;
use App\Models\TextbookPage;
use Smalot\PdfParser\Parser;

/**
 * Extracts textbook content (text + page numbers) so the AI teacher can later
 * retrieve it instead of relying on model memory.
 */
class ContentExtractionService
{
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
     * Store an uploaded page image. When no text is supplied, Arabic OCR is
     * attempted; regardless, the image is preserved as the source of record so
     * no content is guessed and a human can always confirm or correct it.
     */
    public function storeImagePage(Textbook $textbook, int $pageNumber, string $imagePath, ?string $text = null): TextbookPage
    {
        if ($text === null || $text === '') {
            $absolute = Storage::disk(config('platform.storage.textbook_disk', 'public'))->path($imagePath);
            $text = $this->ocrImage($absolute) ?: null;
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
     * Split extracted page text into lessons heuristically, so admins get a
     * draft they can correct rather than a blank slate. Nothing is invented:
     * only text present in the pages becomes a section.
     */
    public function draftLessonsFromPages(Textbook $textbook): int
    {
        $pages = $textbook->pages()->orderBy('page_number')->get();
        $created = 0;
        foreach ($pages as $page) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $page->extracted_text);
            $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
            if (count($lines) < 2) {
                continue;
            }
            $title = $this->guessTitle($lines);
            $lesson = Lesson::create([
                'textbook_id' => $textbook->id,
                'title' => $title,
                'page_from' => $page->page_number,
                'page_to' => $page->page_number,
                'sort_order' => $page->page_number,
                'status' => 'draft',
            ]);
            $order = 0;
            foreach ($lines as $line) {
                if ($line === $title) {
                    continue;
                }
                LessonSection::create([
                    'lesson_id' => $lesson->id,
                    'type' => $this->guessSectionType($line),
                    'content' => $line,
                    'source_page' => $page->page_number,
                    'sort_order' => $order++,
                    'is_from_textbook' => true,
                ]);
            }
            $created++;
        }
        return $created;
    }

    private function guessTitle(array $lines): string
    {
        foreach ($lines as $line) {
            if (mb_strlen($line) <= 60 && !preg_match('/^\d/', $line)) {
                return $line;
            }
        }
        return mb_substr($lines[0], 0, 60);
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
        $len = mb_strlen($text);
        if ($len < 2) {
            return false;
        }
        // Genuine prose is dominated by Arabic letters or lowercase Latin (plus
        // digits on maths pages). Broken-cmap output is instead uppercase-heavy
        // with almost no lowercase, so excluding uppercase separates the two.
        $meaningful = preg_match_all('/[\x{0600}-\x{06FF}a-z0-9]/u', $text);
        return ($meaningful / $len) >= 0.35;
    }
}
