<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Textbook;

/**
 * Exports a whole textbook — or an entire grade — as a single downloadable
 * document (PDF or offline HTML), so learners can download the complete book
 * with its explanations and exercises instead of only viewing lessons online.
 *
 * The content is the same grounded source used by the in-app teacher: every
 * lesson's points come from AiTeacherService::retrieveContext() and carry their
 * textbook page numbers, so the export never invents material.
 */
class TextbookExportService
{
    public function __construct(private AiTeacherService $teacher)
    {
    }

    /**
     * Render one textbook (all lessons: explanation, summary, exercises).
     */
    public function textbookHtml(Textbook $textbook): string
    {
        $textbook->loadMissing(['subject.grade', 'lessons']);

        $body = '';
        foreach ($textbook->lessons as $lesson) {
            $body .= $this->lessonHtml($lesson);
        }

        $subject = $textbook->subject;
        $grade = $subject?->grade;
        $title = $textbook->title;
        $subtitle = trim(implode(' · ', array_filter([
            $grade?->name,
            $subject?->name,
            $textbook->term === 'second' ? 'الفصل الثاني' : 'الفصل الأول',
        ])));

        return $this->document($title, $subtitle, $this->toc($textbook->lessons), $body);
    }

    /**
     * Render an entire grade: every subject's textbooks concatenated.
     */
    public function gradeHtml(Grade $grade): string
    {
        $grade->loadMissing('subjects.textbooks.lessons');

        $body = '';
        $toc = '';
        foreach ($grade->subjects as $subject) {
            foreach ($subject->textbooks as $book) {
                $toc .= '<li>' . e($subject->name . ' — ' . $book->title) . '</li>';
                $body .= '<div class="book-break"><h1 class="subject-head">' . e($subject->name) . ' — ' . e($book->title) . '</h1></div>';
                foreach ($book->lessons as $lesson) {
                    $body .= $this->lessonHtml($lesson);
                }
            }
        }

        return $this->document($grade->name, 'المنهج اليمني — الكتاب كامل', $toc, $body);
    }

    /**
     * Wrap HTML in the shared RTL document shell.
     */
    private function document(string $title, string $subtitle, string $toc, string $body): string
    {
        $owner = config('platform.owner.name', '');

        return '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
            . '<title>' . e($title) . '</title>'
            . '<style>' . $this->css() . '</style></head><body>'
            . '<header class="cover"><h1>' . e($title) . '</h1>'
            . ($subtitle !== '' ? '<p class="sub">' . e($subtitle) . '</p>' : '')
            . '<p class="publisher">وزارة التربية والتعليم — الجمهورية اليمنية</p>'
            . ($owner !== '' ? '<p class="owner">' . e($owner) . '</p>' : '')
            . '</header>'
            . '<section class="toc"><h2>المحتويات</h2><ol>' . $toc . '</ol></section>'
            . $body
            . '</body></html>';
    }

    /**
     * One lesson: grounded explanation points, approved summary, and exercises
     * with their reference answer and step-by-step solution.
     */
    private function lessonHtml(Lesson $lesson): string
    {
        $ctx = $this->teacher->retrieveContext($lesson);
        $lesson->loadMissing(['exercises.options', 'approvedSummary']);

        $pages = ! empty($ctx['pages']) ? ' <span class="pages">(صفحات ' . implode('، ', $ctx['pages']) . ')</span>' : '';

        $html = '<section class="lesson"><h2>' . e($lesson->title) . $pages . '</h2>';

        if (! empty($ctx['points'])) {
            $html .= '<h3>شرح الدرس</h3><ol class="points">';
            $seen = [];
            foreach ($ctx['cited_points'] as $p) {
                $line = trim((string) $p['text']);
                if ($line === '' || isset($seen[$line])) {
                    continue;
                }
                $seen[$line] = true;
                $cite = ! empty($p['page']) ? ' <span class="cite">(ص ' . (int) $p['page'] . ')</span>' : '';
                $html .= '<li>' . e($line) . $cite . '</li>';
            }
            $html .= '</ol>';
        }

        $summary = $lesson->approvedSummary;
        if ($summary && ! empty($summary->points)) {
            $html .= '<h3>الملخص</h3><ul class="summary">';
            foreach ($summary->points as $point) {
                $text = is_array($point) ? ($point['text'] ?? '') : (string) $point;
                if ($text !== '') {
                    $html .= '<li>' . e($text) . '</li>';
                }
            }
            $html .= '</ul>';
        }

        if ($lesson->exercises->isNotEmpty()) {
            $html .= '<h3>التمارين والحلول</h3>';
            foreach ($lesson->exercises as $i => $ex) {
                $html .= '<div class="exercise"><p class="q"><b>' . ($i + 1) . '.</b> ' . e($ex->question) . '</p>';
                if ($ex->options->isNotEmpty()) {
                    $html .= '<ul class="options">';
                    foreach ($ex->options as $opt) {
                        $mark = $opt->is_correct ? ' class="correct"' : '';
                        $html .= '<li' . $mark . '>' . e($opt->text) . '</li>';
                    }
                    $html .= '</ul>';
                }
                if ($ex->answer !== null && $ex->answer !== '') {
                    $html .= '<p class="answer">الجواب: ' . e((string) $ex->answer) . '</p>';
                }
                if (! empty($ex->solution_steps)) {
                    $html .= '<div class="steps"><span>خطوات الحل:</span><ol>';
                    foreach ($ex->solution_steps as $step) {
                        $html .= '<li>' . e((string) $step) . '</li>';
                    }
                    $html .= '</ol></div>';
                }
                $html .= '</div>';
            }
        }

        return $html . '</section>';
    }

    private function toc(iterable $lessons): string
    {
        $out = '';
        foreach ($lessons as $lesson) {
            $range = $lesson->page_from
                ? ' <span class="pages">(ص ' . $lesson->page_from . ($lesson->page_to && $lesson->page_to !== $lesson->page_from ? '–' . $lesson->page_to : '') . ')</span>'
                : '';
            $out .= '<li>' . e($lesson->title) . $range . '</li>';
        }

        return $out;
    }

    /**
     * Render the given HTML to PDF bytes with mPDF (Arabic/RTL aware).
     */
    public function pdf(string $html): string
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    private function css(): string
    {
        return 'body{font-family:dejavusans,sans-serif;direction:rtl;text-align:right;color:#1f2933;line-height:1.9;}'
            . '.cover{text-align:center;border-bottom:3px solid #2f6a43;padding-bottom:14px;margin-bottom:18px;}'
            . '.cover h1{color:#2f6a43;margin:0 0 6px;}'
            . '.cover .sub{font-size:14px;color:#52606d;margin:2px 0;}'
            . '.cover .publisher{font-size:12px;color:#7b8794;margin:6px 0 0;}'
            . '.cover .owner{font-size:12px;color:#2f6a43;margin:2px 0 0;}'
            . '.toc{background:#f4f8f3;border:1px solid #d8e4d6;border-radius:8px;padding:10px 18px;margin-bottom:18px;}'
            . '.toc h2{color:#2f6a43;font-size:16px;margin:6px 0;}'
            . '.toc ol{margin:0;padding-inline-start:22px;}'
            . '.lesson{margin:0 0 22px;padding-bottom:14px;border-bottom:1px dashed #cbd2d9;page-break-inside:avoid;}'
            . '.lesson h2{color:#2f6a43;font-size:17px;margin:14px 0 8px;}'
            . '.lesson h3{color:#3e5c4b;font-size:14px;margin:12px 0 6px;}'
            . '.subject-head{color:#2f6a43;border-top:2px solid #2f6a43;padding-top:10px;}'
            . '.pages,.cite{color:#7b8794;font-size:11px;}'
            . '.points,.summary{padding-inline-start:22px;}'
            . '.exercise{margin:8px 0;padding:8px 12px;background:#fafcf9;border-inline-start:3px solid #9bc49b;}'
            . '.exercise .q{margin:0 0 4px;}'
            . '.options{list-style:none;padding-inline-start:0;margin:4px 0;}'
            . '.options li{display:inline-block;margin:2px 0 2px 10px;padding:2px 10px;border:1px solid #cbd2d9;border-radius:14px;font-size:12px;}'
            . '.options li.correct{border-color:#2f6a43;background:#e8f3e8;color:#2f6a43;font-weight:bold;}'
            . '.answer{color:#2f6a43;font-weight:bold;margin:4px 0;}'
            . '.steps{margin:4px 0;font-size:12px;}';
    }
}
