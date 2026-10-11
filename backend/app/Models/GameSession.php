<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameSession extends Model
{
    protected $fillable = ['student_profile_id', 'game_activity_id', 'score', 'stars', 'duration_seconds', 'completed_at'];
    protected $casts = ['completed_at' => 'datetime'];

    public function student()
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function game()
    {
        return $this->belongsTo(GameActivity::class, 'game_activity_id');
    }
}
