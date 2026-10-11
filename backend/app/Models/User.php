<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_PARENT = 'parent';
    public const ROLE_STUDENT = 'student';

    protected $fillable = [
        'name', 'email', 'password', 'role', 'phone', 'avatar', 'status', 'locale', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function studentProfile()
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function children()
    {
        return $this->hasMany(ParentLink::class, 'parent_user_id');
    }

    public function teacherAssignments()
    {
        return $this->hasMany(TeacherAssignment::class, 'teacher_user_id');
    }

    public function isOwner(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    public function isParent(): bool
    {
        return $this->role === self::ROLE_PARENT;
    }

    public function canManageContent(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_TEACHER], true);
    }
}
