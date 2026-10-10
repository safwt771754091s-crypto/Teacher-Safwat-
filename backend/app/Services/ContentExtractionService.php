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
     * Returns an array of ['page' => int, 'text' => string].
     */
    public function extractPdf(string $absolutePath, Textbook $textbook): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($absolutePath);
        $pages = $pdf->getPages();
        $result = [];
        $number = 1;
        foreach ($pages as $page) {
            $text = trim($page->getText());
            $text = $this->normalizeArabic($text);
            TextbookPage::updateOrCreate(
                ['textbook_id' => $textbook->id, 'page_number' => $number],
                ['extracted_text' => $text, 'status' => $text !== '' ? 'extracted' : 'raw'],
            );
            $result[] = ['page' => $number, 'text' => $text];
            $number++;
        }
        $textbook->update(['pages_count' => count($result), 'status' => 'review']);
        return $result;
    }

    /**
     * Store an uploaded page image. OCR is not bundled offline, so the admin
     * supplies/confirms the text during review; the image is preserved as the
     * source of record so no content is guessed.
     */
    public function storeImagePage(Textbook $textbook, int $pageNumber, string $imagePath, ?string $text = null): TextbookPage
    {
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

    public function normalizeArabic(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }
}
