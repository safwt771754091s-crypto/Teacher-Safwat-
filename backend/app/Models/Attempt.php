<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attempt extends Model
{
    protected $fillable = ['student_profile_id', 'exercise_id', 'answer', 'is_correct', 'score', 'solution_steps', 'hint_used', 'duration_seconds'];
    protected $casts = ['is_correct' => 'boolean', 'solution_steps' => 'array', 'hint_used' => 'boolean'];

    public function student()
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }
}
