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
}
