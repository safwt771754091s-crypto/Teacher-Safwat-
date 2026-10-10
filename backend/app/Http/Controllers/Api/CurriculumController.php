<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Textbook;
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

    public function pageSource(Lesson $lesson, int $page)
    {
        $textbook = $lesson->textbook;
        $pageRow = $textbook->pages()->where('page_number', $page)->first();
        abort_if(! $pageRow, 404, 'الصفحة غير موجودة.');
        return response()->json(['page' => $pageRow]);
    }
}
