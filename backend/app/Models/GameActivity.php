<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameActivity extends Model
{
    protected $fillable = ['grade_id', 'lesson_id', 'subject_id', 'title', 'type', 'difficulty', 'config', 'points'];
    protected $casts = ['config' => 'array'];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
