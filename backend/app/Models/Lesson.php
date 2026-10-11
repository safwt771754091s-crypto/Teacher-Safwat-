<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $fillable = ['textbook_id', 'title', 'page_from', 'page_to', 'sort_order', 'status'];

    public function textbook()
    {
        return $this->belongsTo(Textbook::class);
    }

    public function sections()
    {
        return $this->hasMany(LessonSection::class)->orderBy('sort_order');
    }

    public function exercises()
    {
        return $this->hasMany(Exercise::class)->orderBy('sort_order');
    }

    public function summaries()
    {
        return $this->hasMany(Summary::class);
    }

    public function approvedSummary()
    {
        return $this->hasOne(Summary::class)->where('status', 'approved')->latestOfMany();
    }

    public function audioAssets()
    {
        return $this->hasMany(AudioAsset::class);
    }

    public function subject()
    {
        return $this->textbook?->subject;
    }
}
