<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\GameActivity;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\ParentLink;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersAndGamesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'مالك المنصة', 'slug' => 'owner'],
            ['name' => 'مشرف المحتوى', 'slug' => 'admin'],
            ['name' => 'معلم', 'slug' => 'teacher'],
            ['name' => 'ولي أمر', 'slug' => 'parent'],
            ['name' => 'طالب', 'slug' => 'student'],
        ] as $role) {
            Role::firstOrCreate(['slug' => $role['slug']], $role + ['is_system' => true]);
        }

        $grade1 = Grade::where('level', 1)->first();
        $grade2 = Grade::where('level', 2)->first();

        $owner = User::firstOrCreate(
            ['email' => 'owner@school.ye'],
            ['name' => config('platform.owner.name', 'المالك'), 'password' => Hash::make('password'), 'role' => User::ROLE_OWNER, 'status' => 'active'],
        );

        $teacher = User::firstOrCreate(
            ['email' => 'teacher@school.ye'],
            ['name' => 'الأستاذ محمد', 'password' => Hash::make('password'), 'role' => User::ROLE_TEACHER, 'status' => 'active'],
        );

        $parent = User::firstOrCreate(
            ['email' => 'parent@school.ye'],
            ['name' => 'والد الطالب', 'password' => Hash::make('password'), 'role' => User::ROLE_PARENT, 'status' => 'active'],
        );

        $student = User::firstOrCreate(
            ['email' => 'student@school.ye'],
            ['name' => 'الطالب أحمد', 'password' => Hash::make('password'), 'role' => User::ROLE_STUDENT, 'status' => 'active'],
        );
        $profile = StudentProfile::firstOrCreate(
            ['user_id' => $student->id],
            ['grade_id' => $grade1?->id, 'school' => 'مدرسة النموذجية الأساسية', 'points' => 0],
        );

        $student2 = User::firstOrCreate(
            ['email' => 'student2@school.ye'],
            ['name' => 'الطالبة سارة', 'password' => Hash::make('password'), 'role' => User::ROLE_STUDENT, 'status' => 'active'],
        );
        StudentProfile::firstOrCreate(
            ['user_id' => $student2->id],
            ['grade_id' => $grade2?->id, 'school' => 'مدرسة النموذجية الأساسية'],
        );

        ParentLink::firstOrCreate(
            ['parent_user_id' => $parent->id, 'student_profile_id' => $profile->id],
            ['relation' => 'father', 'status' => 'active'],
        );

        $mathSubject = Subject::where('grade_id', $grade1?->id)->where('name', 'الرياضيات')->first();
        if ($teacher && $grade1 && $mathSubject) {
            TeacherAssignment::firstOrCreate([
                'teacher_user_id' => $teacher->id,
                'grade_id' => $grade1->id,
                'subject_id' => $mathSubject->id,
            ], ['section' => 'أ']);
        }

        // ---- Games ----
        $mathLessons = Lesson::whereHas('textbook.subject', function ($q) use ($mathSubject) {
            $q->where('id', $mathSubject?->id);
        })->get();
        $firstMath = $mathLessons->first();

        $games = [
            ['title' => 'لعبة الأعداد الناقصة', 'type' => 'math', 'difficulty' => 1, 'points' => 10,
                'config' => ['questions' => [
                    ['q' => 'ما العدد بعد ٣؟', 'options' => ['٤', '٥', '٢'], 'answer' => '٤'],
                    ['q' => 'ما العدد قبل ٦؟', 'options' => ['٥', '٧', '٤'], 'answer' => '٥'],
                    ['q' => 'ما العدد بعد ٩؟', 'options' => ['١٠', '٨', '١١'], 'answer' => '١٠'],
                ]]],
            ['title' => 'لعبة الجمع السريع', 'type' => 'math', 'difficulty' => 2, 'points' => 15,
                'config' => ['questions' => [
                    ['q' => '٢ + ٣ = ؟', 'options' => ['٥', '٤', '٦'], 'answer' => '٥'],
                    ['q' => '٤ + ١ = ؟', 'options' => ['٥', '٣', '٦'], 'answer' => '٥'],
                    ['q' => '٥ + ٥ = ؟', 'options' => ['١٠', '٩', '١١'], 'answer' => '١٠'],
                ]]],
            ['title' => 'صنّف: حي أم جماد؟', 'type' => 'sorting', 'difficulty' => 2, 'points' => 15,
                'config' => ['questions' => [
                    ['q' => 'القطة', 'options' => ['كائن حي', 'جماد'], 'answer' => 'كائن حي'],
                    ['q' => 'الحجر', 'options' => ['كائن حي', 'جماد'], 'answer' => 'جماد'],
                    ['q' => 'النبات', 'options' => ['كائن حي', 'جماد'], 'answer' => 'كائن حي'],
                ]]],
            ['title' => 'لعبة الحروف', 'type' => 'reading', 'difficulty' => 1, 'points' => 10,
                'config' => ['questions' => [
                    ['q' => 'أول حرف في كلمة (بَاب)؟', 'options' => ['ب', 'ا', 'ت'], 'answer' => 'ب'],
                    ['q' => 'أول حرف في كلمة (تُفاح)؟', 'options' => ['ت', 'ف', 'ح'], 'answer' => 'ت'],
                ]]],
        ];

        foreach ($games as $g) {
            GameActivity::firstOrCreate(
                ['title' => $g['title']],
                $g + ['grade_id' => $grade1?->id, 'subject_id' => $mathSubject?->id, 'lesson_id' => $firstMath?->id],
            );
        }

        foreach ([
            ['code' => 'first_steps', 'title' => 'الخطوة الأولى', 'icon' => 'star', 'criteria' => ['games' => 1]],
            ['code' => 'game_master', 'title' => 'لاعب ماهر', 'icon' => 'trophy', 'criteria' => ['games' => 5]],
            ['code' => 'perfect_star', 'title' => 'النجوم الثلاث', 'icon' => 'stars', 'criteria' => ['stars' => 3]],
            ['code' => 'point_collector', 'title' => 'جامع النقاط', 'icon' => 'coin', 'criteria' => ['points' => 100]],
        ] as $a) {
            Achievement::firstOrCreate(['code' => $a['code']], $a);
        }
    }
}
