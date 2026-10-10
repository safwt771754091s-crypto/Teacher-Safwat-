<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Summary;
use App\Services\AiTeacherService;
use App\Services\ExerciseService;
use Illuminate\Http\Request;

class AiTeacherController extends Controller
{
    public function __construct(private AiTeacherService $ai)
    {
    }

    public function explain(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'question' => ['nullable', 'string', 'max:1000'],
            'reading_age' => ['nullable', 'integer', 'min:5', 'max:15'],
        ]);

        $result = $this->ai->explain(
            $lesson,
            $data['question'] ?? null,
            $request->user(),
            $data['reading_age'] ?? 8,
        );

        return response()->json($result);
    }

    /**
     * Receives a transcribed voice question and answers it from the lesson source.
     */
    public function ask(Request $request, Lesson $lesson)
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:1000']]);
        return response()->json($this->ai->explain($lesson, $data['question'], $request->user()));
    }

    public function summary(Request $request, Lesson $lesson)
    {
        $summary = $lesson->approvedSummary;
        if (! $summary) {
            $summary = $this->ai->summarize($lesson);
        }
        return response()->json(['summary' => $summary]);
    }
}
