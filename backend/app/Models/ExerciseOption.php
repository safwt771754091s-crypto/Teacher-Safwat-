<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseOption extends Model
{
    protected $fillable = ['exercise_id', 'label', 'text', 'is_correct', 'sort_order'];
    protected $casts = ['is_correct' => 'boolean'];

    public function exercise()
    {
        return $this->belongsTo(Exercise::class);
    }
}
