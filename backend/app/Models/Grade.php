<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = ['name', 'level', 'stage', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function subjects()
    {
        return $this->hasMany(Subject::class)->orderBy('sort_order');
    }
}
