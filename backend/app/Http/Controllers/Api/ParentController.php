<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\GameSession;
use App\Models\LearningProgress;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function dashboard(Request $request)
    {
        $parent = $request->user();
        abort_unless($parent->isParent(), 403, 'هذا المورد لأولياء الأمور فقط.');

        $links = $parent->children()->with('student.grade', 'student.user')->get();

        $children = $links->map(function ($link) {
            $student = $link->student;
            $progress = LearningProgress::with('lesson.textbook.subject')
                ->where('student_profile_id', $student->id)
                ->get();
            $totalTime = $progress->sum('time_spent_seconds');
            $mastered = $progress->where('status', 'mastered')->count();

            $mistakes = Attempt::where('student_profile_id', $student->id)
                ->where('is_correct', false)
                ->selectRaw('exercise_id, count(*) as c')
                ->groupBy('exercise_id')
                ->orderByDesc('c')
                ->with('exercise.lesson')
                ->take(5)
                ->get();

            return [
                'student' => $student,
                'time_spent_seconds' => $totalTime,
                'lessons_started' => $progress->count(),
                'lessons_mastered' => $mastered,
                'mastery_avg' => round($progress->avg('mastery_percent') ?? 0, 1),
                'frequent_mistakes' => $mistakes,
                'games_played' => GameSession::where('student_profile_id', $student->id)->count(),
                'recommendations' => $this->recommendations($student, $progress, $mistakes),
            ];
        });

        return response()->json(['children' => $children]);
    }

    private function recommendations($student, $progress, $mistakes): array
    {
        $recs = [];
        $weak = $progress->where('mastery_percent', '<', 60)->take(3);
        foreach ($weak as $p) {
            if ($p->lesson) {
                $recs[] = 'راجع مع ابنك درس «' . $p->lesson->title . '» واطلب منه شرح النقاط بصوته.';
            }
        }
        if ($mistakes->count() > 0) {
            $recs[] = 'هناك أسئلة يتكرر الخطأ فيها، شجّعه على إعادة حلها بمساعدة المعلم الصوتي.';
        }
        if (empty($recs)) {
            $recs[] = 'أداء جيد، شجّعه على المتابعة اليومية القصيرة.';
        }
        return $recs;
    }
}
