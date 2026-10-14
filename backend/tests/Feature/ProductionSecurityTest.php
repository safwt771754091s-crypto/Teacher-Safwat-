<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the production-hardening behaviour:
 *  - demo accounts are never seeded when SEED_DEMO_USERS is off,
 *  - the owner is created only from environment secrets,
 *  - CORS reflects an explicitly allowed origin.
 */
class ProductionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    private function setDemoFlag(bool $value): void
    {
        config(['platform.seed_demo_users' => $value]);
    }

    public function test_demo_accounts_are_not_seeded_when_disabled(): void
    {
        $this->setDemoFlag(false);

        // migrate:fresh --seed runs once per test process, so demo accounts from
        // a $seed = true class may already exist. Clear them, then prove this
        // seeder does not recreate them when the flag is off.
        User::query()->delete();

        $this->seed(\Database\Seeders\UsersAndGamesSeeder::class);

        $demoEmails = ['owner@school.ye', 'teacher@school.ye', 'parent@school.ye', 'student@school.ye', 'student2@school.ye'];
        $this->assertSame(0, User::whereIn('email', $demoEmails)->count());
        $this->assertSame(0, User::count());
        // Games and achievements are content, not accounts, so they are still seeded.
        // Games are seeded per subject (all grades), plus the shared demo games.
        $this->assertGreaterThanOrEqual(5, \App\Models\GameActivity::count());
        $this->assertDatabaseCount('achievements', 4);
    }

    public function test_demo_accounts_are_seeded_when_enabled(): void
    {
        $this->setDemoFlag(true);

        $this->seed(\Database\Seeders\UsersAndGamesSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'student@school.ye', 'role' => 'student']);
    }

    public function test_bootstrap_owner_creates_owner_from_credentials(): void
    {
        $this->artisan('platform:bootstrap-owner', [
            '--email' => 'fresh-owner@example.com',
            '--password' => 'a-strong-owner-password',
            '--name' => 'المالك الجديد',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'fresh-owner@example.com',
            'role' => User::ROLE_OWNER,
            'status' => 'active',
        ]);
    }

    public function test_bootstrap_owner_rejects_weak_password(): void
    {
        $this->artisan('platform:bootstrap-owner', [
            '--email' => 'weak@example.com',
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_bootstrap_owner_does_not_overwrite_password_by_default(): void
    {
        $existing = User::create([
            'name' => 'Existing',
            'email' => 'existing-owner@example.com',
            'password' => 'original-password-123',
            'role' => User::ROLE_STUDENT,
            'status' => 'active',
        ]);
        $originalHash = $existing->fresh()->password;

        $this->artisan('platform:bootstrap-owner', [
            '--email' => 'existing-owner@example.com',
            '--password' => 'a-different-password-456',
        ])->assertExitCode(0);

        $refreshed = $existing->fresh();
        $this->assertSame(User::ROLE_OWNER, $refreshed->role);
        $this->assertSame($originalHash, $refreshed->password);
    }

    public function test_cors_allows_a_configured_origin(): void
    {
        config(['cors.allowed_origins' => ['https://safwt771754091s-crypto.github.io']]);

        $this->withHeaders(['Origin' => 'https://safwt771754091s-crypto.github.io'])
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://safwt771754091s-crypto.github.io');
    }

    public function test_db_status_command_reports_healthy_schema(): void
    {
        // The migrated test database has the full schema, so the smoke test passes.
        $this->artisan('platform:db-status')->assertExitCode(0);
    }
}
