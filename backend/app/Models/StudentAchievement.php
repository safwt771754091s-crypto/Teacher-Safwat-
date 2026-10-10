<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAchievement extends Model
{
    protected $fillable = ['student_profile_id', 'achievement_id', 'earned_at'];
    protected $casts = ['earned_at' => 'datetime'];

    public function achievement()
    {
        return $this->belongsTo(Achievement::class);
    }
}
