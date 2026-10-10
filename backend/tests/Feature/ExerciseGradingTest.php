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
