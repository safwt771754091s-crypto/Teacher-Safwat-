<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    protected $fillable = ['code', 'title', 'description', 'icon', 'criteria'];
    protected $casts = ['criteria' => 'array'];
}
