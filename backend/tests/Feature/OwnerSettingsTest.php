<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\OfflineAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner configures the AI teacher from the admin console, which writes to the
 * settings table. Those rows used to be written and never read, so a key entered
 * there was silently ignored. These tests protect the wiring that makes it work.
 */
class OwnerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function bootProvider(): void
    {
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_a_key_saved_in_the_console_reaches_the_ai_config(): void
    {
        Setting::set('llm_api_key', 'sk-test-from-console');
        Setting::set('llm_provider', 'openai');

        $this->bootProvider();

        $this->assertSame('sk-test-from-console', config('platform.ai.api_key'));
        $this->assertSame('openai', config('platform.ai.provider'));
    }

    public function test_the_ai_teacher_reports_itself_ready_once_a_key_is_saved(): void
    {
        Setting::set('llm_api_key', 'sk-test-from-console');
        Setting::set('llm_provider', 'openai');

        $this->bootProvider();

        $this->assertTrue(app(OfflineAiService::class)->available());
    }

    public function test_an_environment_variable_wins_over_a_stored_setting(): void
    {
        // Point the app at a real key in the environment, then have the console
        // try to replace it with a different one.
        putenv('LLM_API_KEY=sk-from-env');
        config(['platform.ai.api_key' => 'sk-from-env']);
        Setting::set('llm_api_key', 'sk-from-console');

        try {
            $this->bootProvider();
            $this->assertSame('sk-from-env', config('platform.ai.api_key'));
        } finally {
            putenv('LLM_API_KEY');
        }
    }

    public function test_booting_without_the_settings_table_does_not_crash(): void
    {
        // No RefreshDatabase run: the table is genuinely absent here.
        Setting::query()->getQuery()->getConnection()->statement('DROP TABLE IF EXISTS settings');

        $this->bootProvider();

        $this->assertTrue(true, 'The provider should survive a missing settings table.');
    }

    public function test_the_owner_can_save_settings_through_the_api(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'owner@test.ye'],
            ['name' => 'المالك', 'password' => bcrypt('Owner@Yemen2026'), 'role' => 'owner'],
        );

        $this->actingAs($owner)
            ->postJson('/api/v1/owner/settings', ['key' => 'llm_api_key', 'value' => 'sk-saved-via-api'])
            ->assertOk();

        $this->assertSame('sk-saved-via-api', Setting::get('llm_api_key'));
    }
}
