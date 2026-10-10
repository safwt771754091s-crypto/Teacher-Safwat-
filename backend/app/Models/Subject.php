<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = ['grade_id', 'name', 'code', 'icon', 'color', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function textbooks()
    {
        return $this->hasMany(Textbook::class);
    }
}
