<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Textbook extends Model
{
    protected $fillable = ['subject_id', 'title', 'term', 'edition', 'publisher', 'source_type', 'file_path', 'pages_count', 'status'];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function pages()
    {
        return $this->hasMany(TextbookPage::class)->orderBy('page_number');
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }
}
