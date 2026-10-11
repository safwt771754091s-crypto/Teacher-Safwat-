<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParentLink;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:student,parent,teacher'],
            'grade_id' => ['nullable', 'exists:grades,id'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'phone' => $data['phone'] ?? null,
        ]);

        if ($user->role === User::ROLE_STUDENT) {
            StudentProfile::create([
                'user_id' => $user->id,
                'grade_id' => $data['grade_id'] ?? null,
            ]);
        }

        if ($user->role === User::ROLE_TEACHER) {
            // Teachers start unassigned; an owner assigns them to grades/subjects.
            $user->teacherAssignments()->create([]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->load('studentProfile.grade'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['بيانات الدخول غير صحيحة.']]);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'الحساب موقوف.'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->load(['studentProfile.grade', 'teacherAssignments.subject', 'teacherAssignments.grade']),
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load([
            'studentProfile.grade',
            'teacherAssignments.subject',
            'teacherAssignments.grade',
            'children.student.user',
        ]);

        return response()->json(['user' => $user]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    /**
     * Link an existing student to a parent by the student's email.
     */
    public function linkChild(Request $request)
    {
        $data = $request->validate(['student_email' => ['required', 'email']]);

        $studentUser = User::where('email', $data['student_email'])
            ->where('role', User::ROLE_STUDENT)
            ->firstOrFail();

        $profile = $studentUser->studentProfile()->firstOrFail();

        ParentLink::updateOrCreate(
            ['parent_user_id' => $request->user()->id, 'student_profile_id' => $profile->id],
            ['relation' => 'father', 'status' => 'active'],
        );

        return response()->json(['message' => 'تم ربط الطالب بحسابك.']);
    }
}
