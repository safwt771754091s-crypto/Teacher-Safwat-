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
                if ($this->isUnitHeading($line)) {
                    continue; // unit banners are section context, not lessons
                }
                if ($this->isLessonHeading($line)) {
                    $lessons[] = [
                        'title' => $this->cleanHeading($line),
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
        if (empty($lessons)) {
            $lessons = $this->segmentByUnit($pages);
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
        return (bool) preg_match('/^الدرس\b/u', $line);
    }

    /**
     * Tidy an OCR lesson heading: strip a trailing dot-page leader (". ١٢" or
     * "..") that contents pages append, and collapse whitespace.
     */
    private function cleanHeading(string $line): string
    {
        $line = preg_replace('/[\s.\x{0640}]+$/u', '', trim($line));
        return mb_substr(trim((string) $line), 0, 120);
    }

    private function isUnitHeading(string $line): bool
    {
        return (bool) preg_match('/^(الوحدة|الفصل)\b/u', $line);
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
            $headings = array_values(array_filter($lines, fn ($l) => $this->isUnitHeading($l)));

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
                $units[] = [
                    'title' => 'الوحدة: ' . $this->cleanHeading($heading),
                    'page_from' => $page->page_number,
                    'page_to' => $page->page_number,
                    'sections' => [],
                ];
                $current = count($units) - 1;
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
