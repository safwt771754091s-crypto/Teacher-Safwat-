<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\GameActivity;
use App\Models\GameSession;
use App\Models\StudentAchievement;
use App\Services\GamificationService;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function __construct(private GamificationService $games)
    {
    }

    public function index(Request $request)
    {
        $student = $request->user()->studentProfile;
        $gradeId = $student?->grade_id;

        $games = GameActivity::with('lesson')
            ->when($gradeId, fn ($q) => $q->where(fn ($w) => $w->whereNull('grade_id')->orWhere('grade_id', $gradeId)))
            ->orderBy('difficulty')
            ->get();

        return response()->json(['games' => $games]);
    }

    public function complete(Request $request, GameActivity $game)
    {
        $data = $request->validate([
            'correct' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $student = $request->user()->studentProfile;
        abort_if(! $student, 403, 'هذا الإجراء للطلاب فقط.');

        $session = $this->games->completeGame(
            $student,
            $game->id,
            $data['correct'],
            $data['total'],
            (int) ($data['duration_seconds'] ?? 0),
        );

        return response()->json([
            'session' => $session,
            'points_total' => $student->fresh()->points,
        ]);
    }

    public function achievements(Request $request)
    {
        $student = $request->user()->studentProfile;
        abort_if(! $student, 403, 'هذا المورد للطلاب فقط.');

        $earned = StudentAchievement::with('achievement')
            ->where('student_profile_id', $student->id)
            ->get();

        return response()->json([
            'all' => Achievement::all(),
            'earned' => $earned,
            'points' => $student->points,
        ]);
    }
}
