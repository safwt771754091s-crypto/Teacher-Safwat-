<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningProgress;
use App\Models\Lesson;
use App\Services\ExerciseService;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function __construct(private ExerciseService $exercises)
    {
    }

    public function index(Request $request)
    {
        $student = $request->user()->studentProfile;
        abort_if(! $student, 403, 'هذا المورد للطلاب فقط.');

        $progress = LearningProgress::with('lesson.textbook.subject')
            ->where('student_profile_id', $student->id)
            ->orderByDesc('last_activity_at')
            ->get();

        return response()->json([
            'points' => $student->points,
            'progress' => $progress,
            'weak_points' => $this->exercises->weakPoints($student)->values(),
        ]);
    }

    public function track(Request $request, Lesson $lesson)
    {
        $student = $request->user()->studentProfile;
        abort_if(! $student, 403, 'هذا المورد للطلاب فقط.');

        $data = $request->validate(['seconds' => ['required', 'integer', 'min:1']]);

        $progress = LearningProgress::firstOrNew([
            'student_profile_id' => $student->id,
            'lesson_id' => $lesson->id,
        ]);
        $progress->status = $progress->status === 'mastered' ? 'mastered' : 'in_progress';
        $progress->time_spent_seconds += $data['seconds'];
        $progress->last_activity_at = now();
        $progress->save();

        return response()->json(['progress' => $progress]);
    }
}
