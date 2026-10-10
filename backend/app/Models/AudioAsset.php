<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioAsset extends Model
{
    protected $fillable = ['lesson_id', 'section_id', 'text', 'voice', 'lang', 'file_path', 'duration_seconds', 'status'];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
