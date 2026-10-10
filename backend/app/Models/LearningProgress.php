<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningProgress extends Model
{
    protected $table = 'learning_progress';
    protected $fillable = ['student_profile_id', 'lesson_id', 'status', 'mastery_percent', 'time_spent_seconds', 'last_activity_at'];
    protected $casts = ['last_activity_at' => 'datetime'];

    public function student()
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
