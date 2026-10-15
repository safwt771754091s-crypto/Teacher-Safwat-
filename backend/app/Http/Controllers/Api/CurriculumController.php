<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Textbook;
use App\Services\ContentExtractionService;
use App\Services\TextbookExportService;
use Illuminate\Http\Request;

class CurriculumController extends Controller
{
    public function grades()
    {
        return response()->json(['grades' => Grade::withCount('subjects')->orderBy('sort_order')->get()]);
    }

    public function subjects(Grade $grade)
    {
        return response()->json(['subjects' => $grade->subjects()->withCount('textbooks')->get()]);
    }

    public function textbooks(Subject $subject, Request $request)
    {
        $query = $subject->textbooks()->withCount('lessons');
        if ($authority = $request->query('authority')) {
            $query->whereIn('authority', [$authority, 'common']);
        }
        return response()->json([
            'textbooks' => $query->get(),
            'authorities' => Textbook::AUTHORITIES,
        ]);
    }

    public function lessons(Textbook $textbook)
    {
        $lessons = $textbook->lessons()->get(['id', 'title', 'page_from', 'page_to', 'sort_order', 'status']);
        return response()->json([
            'textbook' => $textbook->only(['id', 'title', 'term', 'status', 'pages_count', 'authority', 'branch']),
            'lessons' => $lessons,
        ]);
    }

    public function lesson(Lesson $lesson)
    {
        $lesson->load(['sections', 'exercises.options', 'approvedSummary', 'textbook.subject.grade']);
        return response()->json(['lesson' => $lesson]);
    }

    public function pageSource(Lesson $lesson, int $page, ContentExtractionService $extractor)
    {
        $textbook = $lesson->textbook;
        $pageRow = $textbook->pages()->where('page_number', $page)->first();
        abort_if(! $pageRow, 404, 'الصفحة غير موجودة.');

        return response()->json([
            'page' => $pageRow,
            // Direct CDN URL for the page image when it is already stored (so the
            // client can bypass the API); null means it must be rendered on demand.
            'image_url' => $extractor->pageImageUrl($textbook, $page),
        ]);
    }

    /**
     * Serve one textbook page as a PNG so the lesson screen can show the book
     * page itself (not only extracted text). Rendered on demand from the book's
     * source PDF and cached for a day, since textbook pages never change.
     *
     * When the page image is already stored on a cloud disk (Supabase Storage),
     * the client is redirected straight to its public CDN URL — the API does not
     * proxy the bytes. A local disk (or a page that still needs rendering) is
     * served as PNG bytes here instead.
     */
    public function pageImage(Lesson $lesson, int $page, ContentExtractionService $extractor)
    {
        $textbook = $lesson->textbook;
        abort_if($page < 1, 404, 'الصفحة غير موجودة.');

        $url = $extractor->pageImageUrl($textbook, $page);
        if ($url !== null) {
            return redirect()->away($url, 302);
        }

        $png = $extractor->pageImage($textbook, $page);
        abort_if($png === null, 404, 'تعذّر عرض هذه الصفحة.');

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($png),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Download a whole textbook (all lessons, explanations, exercises) as a
     * single PDF or offline HTML file.
     */
    public function downloadTextbook(Textbook $textbook, Request $request, TextbookExportService $export)
    {
        $textbook->loadMissing('subject.grade');
        $name = $textbook->title ?: 'textbook';
        $pages = $this->includePages($request);

        return $this->download(fn () => $export->textbookHtml($textbook, $pages), $export, $request, $name);
    }

    /**
     * Download an entire grade: every subject's textbook in one document.
     */
    public function downloadGrade(Grade $grade, Request $request, TextbookExportService $export)
    {
        $pages = $this->includePages($request);

        return $this->download(fn () => $export->gradeHtml($grade, $pages), $export, $request, $grade->name);
    }

    private function includePages(Request $request): bool
    {
        return $request->query('pages', '1') !== '0';
    }

    private function download(callable $htmlFactory, TextbookExportService $export, Request $request, string $name)
    {
        $format = $request->query('format', 'pdf') === 'html' ? 'html' : 'pdf';
        $safe = preg_replace('/[^\p{Arabic}\p{Latin}0-9 \-_]/u', '', $name);
        $safe = trim(preg_replace('/\s+/', ' ', $safe)) ?: 'book';

        if ($format === 'html') {
            return response($htmlFactory(), 200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="book.html"; filename*=UTF-8\'\'' . rawurlencode($safe . '.html'),
            ]);
        }

        $bytes = $export->pdf($htmlFactory());

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="book.pdf"; filename*=UTF-8\'\'' . rawurlencode($safe . '.pdf'),
        ]);
    }
}
