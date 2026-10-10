<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Exercise;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseGradingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function student(): User
    {
        return User::where('email', 'student@school.ye')->first();
    }

    private function teacher(): User
    {
        return User::where('email', 'teacher@school.ye')->first();
    }

    public function test_correct_numeric_answer_is_accepted(): void
    {
        $ex = Exercise::where('question', 'احسب: ٣ + ٤ = ؟')->firstOrFail();
        $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/submit", ['answer' => '7'])
            ->assertOk()
            ->assertJsonPath('is_correct', true);
    }

    public function test_arabic_digit_answer_is_accepted(): void
    {
        $ex = Exercise::where('question', 'احسب: ٣ + ٤ = ؟')->firstOrFail();
        $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/submit", ['answer' => '٧'])
            ->assertOk()
            ->assertJsonPath('is_correct', true);
    }

    public function test_wrong_numeric_answer_is_rejected(): void
    {
        $ex = Exercise::where('question', 'احسب: ٣ + ٤ = ؟')->firstOrFail();
        $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/submit", ['answer' => '9'])
            ->assertOk()
            ->assertJsonPath('is_correct', false)
            ->assertJsonPath('expected', '7');
    }

    public function test_ai_walks_student_through_solution_grounded_in_source(): void
    {
        $ex = Exercise::where('question', 'احسب: ٣ + ٤ = ؟')->firstOrFail();
        $res = $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/ai-solution", [])
            ->assertOk();

        $this->assertTrue($res->json('grounded'));
        $this->assertNotEmpty($res->json('steps'));
        // With no LLM provider configured, steps must derive from the textbook
        // source / deterministic result — never invented.
        $this->assertSame('source', $res->json('provider'));
    }

    public function test_teacher_can_ask_for_a_draft_solution_without_publishing(): void
    {
        $ex = Exercise::where('question', 'احسب: ٣ + ٤ = ؟')->firstOrFail();
        $originalAnswer = $ex->answer;

        $res = $this->actingAs($this->teacher(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/suggest-solution", [])
            ->assertOk()
            ->assertJsonStructure(['suggestion' => ['answer', 'solution_steps', 'hint', 'confidence', 'provider']]);

        // The suggestion is computed deterministically for numeric exercises...
        $this->assertSame('high', $res->json('suggestion.confidence'));
        $this->assertNotEmpty($res->json('suggestion.solution_steps'));
        // ...and nothing is written to the exercise until a human approves it.
        $this->assertSame($originalAnswer, $ex->fresh()->answer);
    }

    public function test_teacher_bulk_drafts_solutions_for_unanswered_exercises(): void
    {
        $lesson = \App\Models\Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        // Leave one exercise without an answer.
        $ex = Exercise::where('lesson_id', $lesson->id)->firstOrFail();
        $ex->update(['answer' => null]);
        $lesson->exercises()->where('id', '!=', $ex->id)->update(['answer' => '']);

        $res = $this->actingAs($this->teacher(), 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/suggest-solutions", [])
            ->assertOk();

        $this->assertGreaterThan(0, $res->json('count'));
        $this->assertNotEmpty($res->json('suggestions.0.suggestion'));
        // Still a draft — the exercise keeps its empty answer.
        $this->assertNull($ex->fresh()->answer);
    }

    public function test_student_cannot_request_a_draft_solution(): void
    {
        $ex = Exercise::where('question', 'احسب: ٣ + ٤ = ؟')->firstOrFail();
        $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/suggest-solution", [])
            ->assertForbidden();
    }

    public function test_mcq_correct_option_is_accepted(): void
    {
        $ex = Exercise::where('type', 'mcq')->firstOrFail();
        $correct = $ex->options()->where('is_correct', true)->firstOrFail();
        $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/submit", ['answer' => $correct->label ?: $correct->text])
            ->assertOk()
            ->assertJsonPath('is_correct', true);
    }

    public function test_attempts_are_recorded_and_progress_updated(): void
    {
        $ex = Exercise::where('question', 'احسب: ٣ + ٤ = ؟')->firstOrFail();
        $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/submit", ['answer' => '7'])
            ->assertOk();

        $profile = StudentProfile::where('user_id', $this->student()->id)->first();
        $this->assertDatabaseHas('attempts', [
            'student_profile_id' => $profile->id,
            'exercise_id' => $ex->id,
            'is_correct' => true,
        ]);
        $this->assertDatabaseHas('learning_progress', [
            'student_profile_id' => $profile->id,
            'lesson_id' => $ex->lesson_id,
        ]);
    }

    public function test_hint_and_solution_are_reference_content(): void
    {
        $ex = Exercise::where('question', 'احسب: ٣ + ٤ = ؟')->firstOrFail();
        $this->actingAs($this->student(), 'sanctum')
            ->getJson("/api/v1/exercises/{$ex->id}/solution")
            ->assertOk()
            ->assertJsonPath('answer', '7')
            ->assertJsonStructure(['steps']);
    }
}
