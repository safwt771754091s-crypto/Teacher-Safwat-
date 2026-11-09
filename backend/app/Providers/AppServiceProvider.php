<?php

namespace App\Providers;

use App\Listeners\SnapshotBeforeDestructiveCommand;
use App\Models\Setting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyStoredSettings();

        // Safety net: snapshot the database before a destructive migrate/seed.
        Event::listen(CommandStarting::class, SnapshotBeforeDestructiveCommand::class);
    }

    /**
     * Let the owner configure the AI teacher from the admin console.
     *
     * The console has always been able to save settings, but nothing read them
     * back, so a key entered there was silently ignored. An environment variable
     * still wins when it is set, so a value pinned in the host's dashboard is
     * never overridden by a stale database row.
     */
    private function applyStoredSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $provider = Setting::get('llm_provider');
            $key = Setting::get('llm_api_key');
            $model = Setting::get('llm_model');

            if ($provider && ! env('LLM_PROVIDER')) {
                config(['platform.ai.provider' => $provider]);
            }
            if ($key && ! env('LLM_API_KEY')) {
                config(['platform.ai.api_key' => $key]);
            }
            if ($model && ! env('LLM_MODEL')) {
                config(['platform.ai.model' => $model]);
            }
        } catch (Throwable) {
            // A missing or unreachable database must never stop the app booting.
        }
    }
}
