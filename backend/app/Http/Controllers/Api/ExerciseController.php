<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Services\ExerciseService;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
    public function __construct(private ExerciseService $exercises)
    {
    }

    public function index(Lesson $lesson)
    {
        return response()->json(['exercises' => $lesson->exercises()->with('options')->get()]);
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
}
