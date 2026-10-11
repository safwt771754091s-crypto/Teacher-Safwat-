<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiTeacherTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_explanation_is_grounded_in_lesson_source(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/explain", [])
            ->assertOk();

        $this->assertTrue($res->json('grounded'));
        $this->assertNotEmpty($res->json('pages'));
        // Every point must come from the lesson content, not invent new facts.
        $joined = implode(' ', $res->json('explanation'));
        $this->assertStringContainsString('الجمع', $joined);
        $this->assertStringContainsString('زائد', $joined);
        // Each explained point carries its textbook page so the student can
        // verify it, rather than trusting a free-floating claim.
        $this->assertStringContainsString('صفحة', $joined);
    }

    public function test_lesson_without_content_does_not_invent(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $lesson->sections()->delete();
        $lesson->summaries()->delete();

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/explain", [])
            ->assertOk();

        $this->assertTrue($res->json('needs_content'));
        $res->assertJsonPath('grounded', true);
    }

    public function test_asking_a_question_returns_source_scoped_answer(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/ask", ['question' => 'ما علامة الجمع؟'])
            ->assertOk();
        $this->assertTrue($res->json('grounded'));
    }

    public function test_summary_endpoint_returns_draft(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $lesson = Lesson::where('title', 'الطرح حتى ١٠')->firstOrFail();
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/lessons/{$lesson->id}/summary")
            ->assertOk()
            ->assertJsonStructure(['summary' => ['points', 'source_pages']]);
    }
}
