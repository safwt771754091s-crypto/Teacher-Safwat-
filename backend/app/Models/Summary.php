<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Summary extends Model
{
    protected $fillable = ['lesson_id', 'title', 'points', 'keywords', 'source_pages', 'generated_by', 'status', 'approved_by', 'approved_at'];
    protected $casts = ['points' => 'array', 'keywords' => 'array', 'source_pages' => 'array', 'approved_at' => 'datetime'];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
