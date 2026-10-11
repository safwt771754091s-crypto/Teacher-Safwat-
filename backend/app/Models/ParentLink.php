<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentLink extends Model
{
    protected $fillable = ['parent_user_id', 'student_profile_id', 'relation', 'status'];

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_user_id');
    }

    public function student()
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }
}
