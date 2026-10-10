<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_grades_are_public_and_include_all_stages(): void
    {
        $res = $this->getJson('/api/v1/grades')->assertOk();
        $this->assertCount(12, $res->json('grades'));
    }

    public function test_grade_one_has_yemeni_subjects(): void
    {
        $grade = Grade::where('level', 1)->first();
        $res = $this->getJson("/api/v1/grades/{$grade->id}/subjects")->assertOk();
        $names = collect($res->json('subjects'))->pluck('name');
        $this->assertTrue($names->contains('الرياضيات'));
        $this->assertTrue($names->contains('لغتي الجميلة (العربية)'));
        $this->assertTrue($names->contains('العلوم'));
    }

    public function test_lesson_exposes_sections_with_source_pages(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $res = $this->getJson("/api/v1/lessons/{$lesson->id}")->assertOk();
        foreach ($res->json('lesson.sections') as $s) {
            $this->assertNotNull($s['source_page']);
        }
    }

    public function test_page_source_is_retrievable(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $this->getJson("/api/v1/lessons/{$lesson->id}/pages/17")
            ->assertOk();
    }

    public function test_every_grade_has_subjects_and_published_lessons(): void
    {
        foreach (Grade::all() as $grade) {
            $subjects = $grade->subjects()->withCount('textbooks')->get();
            $this->assertGreaterThanOrEqual(2, $subjects->count(), "grade {$grade->level} has too few subjects");

            $lessons = Lesson::whereHas('textbook.subject', fn ($q) => $q->where('grade_id', $grade->id))
                ->where('status', 'published')
                ->count();
            $this->assertGreaterThanOrEqual(4, $lessons, "grade {$grade->level} has too few lessons");
        }
    }

    public function test_secondary_grade_subjects_are_browsable(): void
    {
        $grade10 = Grade::where('level', 10)->firstOrFail();
        $res = $this->getJson("/api/v1/grades/{$grade10->id}/subjects")->assertOk();
        $names = collect($res->json('subjects'))->pluck('name');
        $this->assertTrue($names->contains('الفيزياء'));
        $this->assertTrue($names->contains('الكيمياء'));

        $physics = $grade10->subjects()->where('name', 'الفيزياء')->firstOrFail();
        $book = $physics->textbooks()->firstOrFail();
        $lessons = $this->getJson("/api/v1/textbooks/{$book->id}/lessons")->assertOk();
        $this->assertNotEmpty($lessons->json('lessons'));
    }
}
