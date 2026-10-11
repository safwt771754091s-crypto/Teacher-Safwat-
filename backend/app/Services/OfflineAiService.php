<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

/**
 * The single gateway to an optional cloud LLM.
 *
 * Offline-first guarantee: every agent works with no provider configured. When
 * a provider *is* configured the LLM is only ever used to rephrase/structure
 * content that was already retrieved from the textbook — it is never the source
 * of truth. Callers pass a deterministic `fallback` so the same call returns a
 * real answer whether or not the network is available.
 */
class OfflineAiService
{
    public function provider(): string
    {
        return (string) config('platform.ai.provider', 'none');
    }

    /** True only when a cloud provider is configured with a key. */
    public function available(): bool
    {
        return $this->provider() !== 'none' && ! empty(config('platform.ai.api_key'));
    }

    /**
     * Ask the LLM, returning null when offline/unavailable or on failure so the
     * caller can fall back to deterministic composition.
     */
    public function complete(
        string $system,
        string $userMessage,
        string $feature,
        ?User $user = null,
        ?Model $subject = null,
    ): ?string {
        if (! $this->available()) {
            return null;
        }

        $provider = $this->provider();
        $apiKey = (string) config('platform.ai.api_key');

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('platform.ai.timeout', 30))
                ->post(rtrim((string) config('platform.ai.base_url'), '/').'/chat/completions', [
                    'model' => config('platform.ai.model'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                    'temperature' => 0.2,
                ]);

            if (! $response->successful()) {
                $this->log($user, $subject, $feature, $response, false, 'HTTP '.$response->status());
                return null;
            }

            $this->log($user, $subject, $feature, $response, true);
            $text = (string) $response->json('choices.0.message.content', '');
            return trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            $this->log($user, $subject, $feature, null, false, $e->getMessage());
            return null;
        }
    }

    /**
     * Return the LLM's grounded rephrasing when online, otherwise the supplied
     * deterministic fallback. `offline` reports which path was taken.
     *
     * @return array{text: string, offline: bool, provider: string}
     */
    public function grounded(
        string $system,
        string $userMessage,
        string $fallback,
        string $feature,
        ?User $user = null,
        ?Model $subject = null,
    ): array {
        $text = $this->complete($system, $userMessage, $feature, $user, $subject);

        return $text === null
            ? ['text' => $fallback, 'offline' => true, 'provider' => 'source']
            : ['text' => $text, 'offline' => false, 'provider' => $this->provider()];
    }

    private function log(?User $user, ?Model $subject, string $feature, $response, bool $success, ?string $error = null): void
    {
        AiUsageLog::create([
            'user_id' => $user?->id,
            'lesson_id' => $subject instanceof \App\Models\Lesson ? $subject->id : null,
            'feature' => $feature,
            'provider' => $this->provider(),
            'model' => config('platform.ai.model'),
            'prompt_tokens' => (int) ($response?->json('usage.prompt_tokens') ?? 0),
            'completion_tokens' => (int) ($response?->json('usage.completion_tokens') ?? 0),
            'success' => $success,
            'error' => $error,
        ]);
    }
}
