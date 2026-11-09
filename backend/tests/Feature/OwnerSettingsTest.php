<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\LlmKeyValidator;
use App\Services\OfflineAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        // The provider is the one thing we do not own; a live call is replaced by
        // a faked success so the test exercises our wiring, not OpenAI's uptime.
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson('/api/v1/owner/settings', ['key' => 'llm_api_key', 'value' => 'sk-proj-a-valid-key'])
            ->assertOk();

        $this->assertSame('sk-proj-a-valid-key', Setting::get('llm_api_key'));
    }

    public function test_a_key_the_provider_rejects_is_not_stored(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'expired_secret_key', 'message' => 'Your API key has expired.'],
        ], 401)]);

        $this->actingAs($this->owner())
            ->postJson('/api/v1/owner/settings', ['key' => 'llm_api_key', 'value' => 'sk-proj-expired'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'منتهي الصلاحية'));

        $this->assertNull(Setting::get('llm_api_key'));
    }

    public function test_an_openhands_cloud_key_is_refused_before_any_call(): void
    {
        Http::fake();

        $this->actingAs($this->owner())
            ->postJson('/api/v1/owner/settings', ['key' => 'llm_api_key', 'value' => 'sk-oh-abc123'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'OpenHands Cloud'));

        Http::assertNothingSent();
        $this->assertNull(Setting::get('llm_api_key'));
    }

    public function test_the_validator_accepts_a_working_key(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        $result = app(LlmKeyValidator::class)->check('sk-proj-good');

        $this->assertTrue($result['ok']);
    }

    public function test_an_out_of_credit_account_is_reported_as_such(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'credit_balance_exhausted', 'message' => 'You have no credits remaining.'],
        ], 429)]);

        $result = app(LlmKeyValidator::class)->check('sk-proj-valid-but-broke');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('بلا رصيد', $result['message']);
    }

    public function test_a_key_pasted_into_the_model_field_is_refused(): void
    {
        Http::fake();

        $result = app(LlmKeyValidator::class)->check('sk-proj-good', 'openai', 'sk-proj-oops');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ليس اسم نموذج', $result['message']);
        Http::assertNothingSent();
    }

    public function test_choosing_a_provider_selects_its_endpoint(): void
    {
        Setting::set('llm_provider', 'openrouter');

        (new AppServiceProvider(app()))->boot();

        $this->assertSame('https://openrouter.ai/api/v1', config('platform.ai.base_url'));
    }

    public function test_a_non_key_setting_is_saved_without_validation(): void
    {
        Http::fake();

        $this->actingAs($this->owner())
            ->postJson('/api/v1/owner/settings', ['key' => 'llm_model', 'value' => 'gpt-4o-mini'])
            ->assertOk();

        Http::assertNothingSent();
        $this->assertSame('gpt-4o-mini', Setting::get('llm_model'));
    }

    private function owner(): User
    {
        return User::firstOrCreate(
            ['email' => 'owner@test.ye'],
            ['name' => 'المالك', 'password' => bcrypt('Owner@Yemen2026'), 'role' => 'owner'],
        );
    }
}
