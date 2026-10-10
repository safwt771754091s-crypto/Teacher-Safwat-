<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonSection extends Model
{
    protected $fillable = ['lesson_id', 'type', 'title', 'content', 'source_page', 'sort_order', 'is_from_textbook'];
    protected $casts = ['is_from_textbook' => 'boolean'];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
