<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exercise extends Model
{
    protected $fillable = ['lesson_id', 'type', 'question', 'answer', 'solution_steps', 'hint', 'source_page', 'difficulty', 'sort_order', 'source', 'is_draft', 'verified_by', 'verified_at'];
    protected $casts = ['solution_steps' => 'array', 'is_draft' => 'boolean', 'verified_at' => 'datetime'];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function options()
    {
        return $this->hasMany(ExerciseOption::class)->orderBy('sort_order');
    }
}
