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

        $mathSubject = Subject::where('grade_id', $grade1?->id)->where('name', 'الرياضيات')->first();

        // Demo accounts are a development convenience only. In production the
        // owner is created through `php artisan platform:bootstrap-owner`
        // (using an environment secret), and real accounts come from
        // registration, so we never ship a default password.
        if ($this->demoUsersEnabled()) {
            $this->seedDemoUsers($grade1, $grade2, $mathSubject);
        }

        $this->seedGames($grade1, $mathSubject);
        $this->seedAchievements();
    }

    /**
     * Whether to create the shared demo accounts. Enabled everywhere except
     * production, and can be forced with the SEED_DEMO_USERS env flag.
     */
    private function demoUsersEnabled(): bool
    {
        return (bool) config('platform.seed_demo_users');
    }

    private function seedDemoUsers(?Grade $grade1, ?Grade $grade2, ?Subject $mathSubject): void
    {
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

        if ($teacher && $grade1 && $mathSubject) {
            TeacherAssignment::firstOrCreate([
                'teacher_user_id' => $teacher->id,
                'grade_id' => $grade1->id,
                'subject_id' => $mathSubject->id,
            ], ['section' => 'أ']);
        }
    }

    private function seedGames(?Grade $grade1, ?Subject $mathSubject): void
    {
        // ---- Games ----
        $mathLessons = Lesson::whereHas('textbook.subject', function ($q) use ($mathSubject) {
            $q->where('id', $mathSubject?->id);
        })->get();
        $firstMath = $mathLessons->first();

        // Procedural math questions from the curriculum reference so every math
        // game ships with a large, varied, self-answered question set.
        $bank = app(\App\Services\MathQuestionBank::class);
        $band = $bank->bandForGrade($grade1?->level ?? 1);

        $games = [
            ['title' => 'لعبة الأعداد الناقصة', 'type' => 'math', 'difficulty' => 1, 'points' => 10,
                'config' => ['questions' => $bank->pool($band, 10)]],
            ['title' => 'لعبة الجمع السريع', 'type' => 'math', 'difficulty' => 2, 'points' => 15,
                'config' => ['questions' => $bank->pool($band, 12)]],
            ['title' => 'تحدي الجدول والكسور', 'type' => 'math', 'difficulty' => 3, 'points' => 20,
                'config' => ['questions' => $bank->pool('g4_6', 12)]],
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
            // updateOrCreate so re-seeding refreshes the generated question pools.
            GameActivity::updateOrCreate(
                ['title' => $g['title']],
                $g + ['grade_id' => $grade1?->id, 'subject_id' => $mathSubject?->id, 'lesson_id' => $firstMath?->id],
            );
        }
    }

    private function seedAchievements(): void
    {
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
