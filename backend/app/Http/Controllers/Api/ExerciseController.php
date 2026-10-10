<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Services\AiTeacherService;
use App\Services\ExerciseService;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
    public function __construct(
        private ExerciseService $exercises,
        private AiTeacherService $ai,
    ) {
    }

    public function index(Lesson $lesson)
    {
        // Drafts (questions surfaced from scanned pages, no answer yet) are
        // withheld from learners until a human verifies them. Authored/teacher
        // exercises are always shown.
        return response()->json([
            'exercises' => $lesson->exercises()->where('is_draft', false)->with('options')->get(),
        ]);
    }

    public function submit(Request $request, Exercise $exercise)
    {
        $data = $request->validate([
            'answer' => ['nullable', 'string', 'max:2000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'hint_used' => ['nullable', 'boolean'],
        ]);

        $student = $request->user()->studentProfile;
        abort_if(! $student, 403, 'هذا الإجراء للطلاب فقط.');

        $result = $this->exercises->grade($exercise, $data['answer'] ?? null, (bool) ($data['hint_used'] ?? false));
        $this->exercises->recordAttempt(
            $student,
            $exercise,
            $data['answer'] ?? null,
            $result,
            (int) ($data['duration_seconds'] ?? 0),
            (bool) ($data['hint_used'] ?? false),
        );

        return response()->json([
            'is_correct' => $result['is_correct'],
            'feedback' => $result['feedback'],
            'expected' => $result['expected'] ?? null,
        ]);
    }

    public function hint(Exercise $exercise)
    {
        return response()->json(['hint' => $exercise->hint ?: 'أعد قراءة نقاط الدرس وركز على المطلوب.']);
    }

    public function solution(Exercise $exercise)
    {
        return response()->json([
            'steps' => $exercise->solution_steps ?? [],
            'answer' => $exercise->answer,
            'source_page' => $exercise->source_page,
        ]);
    }

    /**
     * The AI teacher walks the student through *how to solve* this exercise,
     * grounded in the lesson's textbook content. Draft exercises are still
     * visible here so a student can get help, but they always cite the source.
     */
    public function aiSolution(Request $request, Exercise $exercise)
    {
        return response()->json($this->ai->explainSolution($exercise, $request->user()));
    }
}
