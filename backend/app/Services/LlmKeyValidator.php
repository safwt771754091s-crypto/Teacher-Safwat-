<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks an LLM key against the provider before the owner stores it.
 *
 * Saving a key that the provider will reject used to be silent: the row was
 * written, the AI teacher quietly stayed on its grounded-source fallback, and
 * nothing told the owner why. A key is now proven with a real, minimal request
 * so a bad or expired key is refused with the provider's own words.
 */
class LlmKeyValidator
{
    /**
     * @return array{ok: bool, message: string}
     */
    public function check(string $apiKey, ?string $provider = null, ?string $model = null, ?string $baseUrl = null): array
    {
        $apiKey = trim($apiKey);
        $provider = $provider ?: (string) (Setting::get('llm_provider') ?: config('platform.ai.provider', 'openai'));
        $keyless = in_array($provider, (array) config('platform.ai.keyless', []), true);

        if ($apiKey === '' && ! $keyless) {
            return ['ok' => false, 'message' => 'المفتاح فارغ.'];
        }

        if (str_starts_with($apiKey, 'sk-oh-')) {
            return [
                'ok' => false,
                'message' => 'هذا مفتاح OpenHands Cloud (يبدأ بـ sk-oh-)، وليس مفتاح نموذج لغوي. '
                    .'المنصة تحتاج مفتاح OpenAI يبدأ بـ sk-proj- أو sk-.',
            ];
        }

        $provider = $provider ?: (string) (Setting::get('llm_provider') ?: config('platform.ai.provider', 'openai'));
        $model = $model ?: (string) (Setting::get('llm_model') ?: config('platform.ai.model', 'gpt-4o-mini'));

        // A key is being saved, so the owner intends to switch the teacher on.
        // "none" means "not configured yet", not "no provider to check against".
        if ($provider === 'none') {
            $provider = 'openai';
        }

        $endpoints = (array) config('platform.ai.endpoints', []);
        $baseUrl = rtrim(
            $baseUrl ?: ($endpoints[$provider] ?? null) ?: (string) config('platform.ai.base_url', 'https://api.openai.com/v1'),
            '/'
        );

        // A key that is valid but cannot generate (an exhausted account) would
        // still leave the teacher silent, so it is treated as a failure too.
        if ($model === '' || str_starts_with($model, 'sk-') || str_starts_with($model, 'key_')) {
            return [
                'ok' => false,
                'message' => 'اسم النموذج «'.$model.'» ليس اسم نموذج — يبدو أنه مفتاح وُضع في حقل النموذج. '
                    .'اتركه فارغًا أو اكتب مثل: gpt-4o-mini',
            ];
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                    'max_tokens' => 1,
                ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'تعذّر الوصول إلى مزوّد النموذج: '.$e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'المفتاح صالح ويعمل.'];
        }

        $code = (string) $response->json('error.code', '');
        $detail = (string) $response->json('error.message', '');

        if ($response->status() === 401 || $code === 'invalid_api_key' || $code === 'expired_secret_key') {
            return [
                'ok' => false,
                'message' => 'المفتاح غير صالح أو منتهي الصلاحية'.($detail ? ': '.$detail : '.'),
            ];
        }

        if ($response->status() === 404 && str_contains($detail, 'model')) {
            return ['ok' => false, 'message' => 'المفتاح صالح لكن اسم النموذج «'.$model.'» غير متاح للحساب.'];
        }

        // The key authenticates but the account cannot generate: the teacher would
        // stay silent, so say so plainly instead of storing a key that cannot work.
        if ($response->status() === 429 || in_array($code, ['insufficient_quota', 'credit_balance_exhausted', 'billing_hard_limit_reached'], true)) {
            return [
                'ok' => false,
                'message' => 'المفتاح صحيح لكن الحساب بلا رصيد (أو بلغ حدّه). أضف رصيدًا في '.$provider
                    .' أو اختر مزوّدًا آخر.'.($detail ? ' — '.$detail : ''),
            ];
        }

        return [
            'ok' => false,
            'message' => 'رفض المزوّد الطلب (HTTP '.$response->status().')'.($detail ? ': '.$detail : '.'),
        ];
    }
}
