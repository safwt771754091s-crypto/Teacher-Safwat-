<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    protected $fillable = ['user_id', 'grade_id', 'birthdate', 'school', 'avatar', 'points'];
    protected $casts = ['birthdate' => 'date'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function progress()
    {
        return $this->hasMany(LearningProgress::class);
    }

    public function attempts()
    {
        return $this->hasMany(Attempt::class);
    }

    public function parents()
    {
        return $this->hasMany(ParentLink::class);
    }
}
