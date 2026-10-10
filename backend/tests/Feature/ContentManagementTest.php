<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Textbook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function owner(): User
    {
        return User::where('email', 'owner@school.ye')->first();
    }

    public function test_owner_can_create_textbook_and_lesson(): void
    {
        $subject = Subject::first();
        $res = $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/textbooks', [
                'subject_id' => $subject->id,
                'title' => 'كتاب تجريبي',
                'term' => 'first',
                'source_type' => 'manual',
            ])->assertStatus(201);

        $bookId = $res->json('textbook.id');
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/textbooks/{$bookId}/lessons", ['title' => 'درس تجريبي'])
            ->assertStatus(201);
    }

    public function test_generate_and_approve_summary_flow(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $res = $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/generate-summary", [])
            ->assertOk();

        $summaryId = $res->json('summary.id');
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/summaries/{$summaryId}/approve", [])
            ->assertOk();
        $this->assertDatabaseHas('summaries', ['id' => $summaryId, 'status' => 'approved']);
    }

    public function test_verify_exercise_records_reviewer(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $ex = $lesson->exercises()->first();
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/verify", [])
            ->assertOk();
        $this->assertDatabaseHas('exercises', ['id' => $ex->id, 'verified_by' => $this->owner()->id]);
    }

    public function test_owner_overview_and_ai_usage(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/owner/overview')
            ->assertOk()
            ->assertJsonStructure(['counts' => ['users', 'lessons']]);
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/owner/ai-usage')
            ->assertOk()
            ->assertJsonStructure(['total_requests', 'by_feature']);
    }

    public function test_teacher_can_view_struggling_lessons(): void
    {
        $teacher = User::where('email', 'teacher@school.ye')->first();
        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/struggling-lessons')
            ->assertOk()
            ->assertJsonStructure(['lessons']);
    }
}
