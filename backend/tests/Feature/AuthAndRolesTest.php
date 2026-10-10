<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndRolesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_student_can_login(): void
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'student@school.ye',
            'password' => 'password',
        ]);
        $res->assertOk()->assertJsonStructure(['user', 'token']);
        $this->assertSame('student', $res->json('user.role'));
    }

    public function test_login_rejects_bad_password(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'student@school.ye',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    public function test_student_cannot_access_owner_endpoints(): void
    {
        $student = User::where('email', 'student@school.ye')->first();
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/owner/users')
            ->assertStatus(403);
    }

    public function test_student_cannot_submit_another_students_data_path(): void
    {
        // A student hitting teacher-only content endpoints is forbidden.
        $student = User::where('email', 'student@school.ye')->first();
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/textbooks', ['subject_id' => 1, 'title' => 'x', 'term' => 'first', 'source_type' => 'manual'])
            ->assertStatus(403);
    }

    public function test_owner_can_list_users(): void
    {
        $owner = User::where('email', 'owner@school.ye')->first();
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/owner/users')
            ->assertOk()
            ->assertJsonStructure(['users' => ['data']]);
    }

    public function test_cannot_register_owner_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'hacker',
            'email' => 'hacker@x.ye',
            'password' => 'password',
            'role' => 'owner',
        ])->assertStatus(422);
    }
}
