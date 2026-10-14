<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Textbook extends Model
{
    protected $fillable = ['subject_id', 'title', 'term', 'edition', 'publisher', 'authority', 'branch', 'source_type', 'file_path', 'source_url', 'pages_count', 'status'];

    public const AUTHORITIES = ['common' => 'مشترك', 'sanaa' => 'صنعاء', 'aden' => 'عدن'];

    public const BRANCHES = ['general' => 'عام', 'scientific' => 'علمي', 'literary' => 'أدبي'];

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
