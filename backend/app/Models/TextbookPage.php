<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TextbookPage extends Model
{
    protected $fillable = ['textbook_id', 'page_number', 'image_path', 'extracted_text', 'status', 'review_note'];

    public function textbook()
    {
        return $this->belongsTo(Textbook::class);
    }
}
