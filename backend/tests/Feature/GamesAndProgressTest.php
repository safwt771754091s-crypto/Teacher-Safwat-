<?php

namespace Tests\Feature;

use App\Models\GameActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamesAndProgressTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function student(): User
    {
        return User::where('email', 'student@school.ye')->first();
    }

    public function test_games_are_listed_for_student(): void
    {
        $this->actingAs($this->student(), 'sanctum')
            ->getJson('/api/v1/games')
            ->assertOk()
            ->assertJsonStructure(['games']);
    }

    public function test_completing_a_game_awards_points_and_achievement(): void
    {
        $game = GameActivity::firstOrFail();
        $res = $this->actingAs($this->student(), 'sanctum')
            ->postJson("/api/v1/games/{$game->id}/complete", ['correct' => 3, 'total' => 3, 'duration_seconds' => 30])
            ->assertOk();

        $this->assertSame(3, $res->json('session.stars'));
        $this->assertGreaterThan(0, $res->json('points_total'));
        $this->assertDatabaseHas('achievements', ['code' => 'first_steps']);
    }

    public function test_parent_only_sees_linked_child(): void
    {
        $parent = User::where('email', 'parent@school.ye')->first();
        $res = $this->actingAs($parent, 'sanctum')
            ->getJson('/api/v1/parent/dashboard')
            ->assertOk();
        $this->assertCount(1, $res->json('children'));
    }

    public function test_student_cannot_access_parent_dashboard(): void
    {
        $this->actingAs($this->student(), 'sanctum')
            ->getJson('/api/v1/parent/dashboard')
            ->assertStatus(403);
    }

    public function test_student_progress_endpoint(): void
    {
        $this->actingAs($this->student(), 'sanctum')
            ->getJson('/api/v1/progress')
            ->assertOk()
            ->assertJsonStructure(['points', 'progress', 'weak_points']);
    }
}
