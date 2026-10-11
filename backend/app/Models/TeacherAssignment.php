<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherAssignment extends Model
{
    protected $fillable = ['teacher_user_id', 'grade_id', 'subject_id', 'section'];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }
}
