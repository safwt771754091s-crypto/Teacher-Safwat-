<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\GameSession;
use App\Models\StudentAchievement;
use App\Models\StudentProfile;

/**
 * Points, stars and achievements for the primary-school games.
 */
class GamificationService
{
    public function completeGame(StudentProfile $student, int $gameActivityId, int $correct, int $total, int $durationSeconds = 0): GameSession
    {
        $perCorrect = config('platform.game.points_per_correct', 10);
        $score = $correct * $perCorrect;
        $ratio = $total > 0 ? $correct / $total : 0;
        $stars = $ratio >= 1 ? 3 : ($ratio >= 0.7 ? 2 : ($ratio >= 0.4 ? 1 : 0));

        $session = GameSession::create([
            'student_profile_id' => $student->id,
            'game_activity_id' => $gameActivityId,
            'score' => $score,
            'stars' => $stars,
            'duration_seconds' => $durationSeconds,
            'completed_at' => now(),
        ]);

        $student->increment('points', $score);
        $this->evaluateAchievements($student);

        return $session;
    }

    public function evaluateAchievements(StudentProfile $student): array
    {
        $student->refresh();
        $earned = [];
        $already = StudentAchievement::where('student_profile_id', $student->id)->pluck('achievement_id')->all();
        $sessions = GameSession::where('student_profile_id', $student->id)->count();

        foreach (Achievement::all() as $achievement) {
            if (in_array($achievement->id, $already, true)) {
                continue;
            }
            $criteria = $achievement->criteria ?? [];
            $ok = match ($achievement->code) {
                'first_steps' => $sessions >= 1,
                'game_master' => $sessions >= 5,
                'perfect_star' => GameSession::where('student_profile_id', $student->id)->where('stars', 3)->exists(),
                'point_collector' => $student->points >= 100,
                default => false,
            };
            if ($ok) {
                StudentAchievement::create([
                    'student_profile_id' => $student->id,
                    'achievement_id' => $achievement->id,
                    'earned_at' => now(),
                ]);
                $earned[] = $achievement->title;
            }
        }
        return $earned;
    }
}
