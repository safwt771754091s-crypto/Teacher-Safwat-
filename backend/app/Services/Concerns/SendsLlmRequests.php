<?php

namespace App\Services\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP behaviour for the optional cloud LLM.
 *
 * Some providers are free and answer without a key, and those free tiers limit
 * how often they reply. A single AI feature makes several calls in a row, so a
 * burst would otherwise fail halfway and drop the run back to the deterministic
 * fallback for no real reason. One retry loop here keeps every caller honest.
 */
trait SendsLlmRequests
{
    /** A provider that answers without a key (a free endpoint). */
    protected function keylessProvider(): bool
    {
        return in_array(
            (string) config('platform.ai.provider', 'none'),
            (array) config('platform.ai.keyless', []),
            true
        );
    }

    /** True when the teacher can actually answer: a key, or a keyless provider. */
    protected function llmReady(): bool
    {
        return config('platform.ai.provider', 'none') !== 'none'
            && ($this->keylessProvider() || ! empty(config('platform.ai.api_key')));
    }

    /** Post a chat request, retrying when the provider asks us to slow down. */
    protected function postChat(array $payload, ?string $apiKey = null): Response
    {
        $url = rtrim((string) config('platform.ai.base_url'), '/').'/chat/completions';
        $timeout = (int) config('platform.ai.timeout', 30);
        $attempts = max(1, (int) config('platform.ai.retries', 3));

        for ($attempt = 1; ; $attempt++) {
            // A keyless provider answers without a key; sending an empty bearer
            // makes some of them reject the request, so only add it when present.
            $request = Http::timeout($timeout);
            if (is_string($apiKey) && trim($apiKey) !== '') {
                $request = $request->withToken($apiKey);
            }
            $response = $request->post($url, $payload);

            if ($response->successful() || $attempt >= $attempts) {
                return $response;
            }

            // A rate limit or a server fault may pass on a second try; an auth or
            // model error will not change, so return it immediately.
            if ($response->status() !== 429 && $response->status() < 500) {
                return $response;
            }

            sleep(2 * $attempt);
        }
    }
}
