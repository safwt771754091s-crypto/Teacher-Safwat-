<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Server-side Arabic text-to-speech.
 *
 * Many low-cost Android devices used in the target market ship without an
 * Arabic TTS voice, so the browser's SpeechSynthesis stays silent for Arabic.
 * When that happens the client asks this service instead: it synthesises the
 * Arabic narration on the server (where a voice is always available) and
 * returns MP3 bytes the client can play with a plain <audio> element.
 *
 * The text spoken is always the lesson's approved source content — never
 * generated wording — so the audio stays grounded in the textbook.
 */
class TtsService
{
    /** Characters per request, kept below the upstream ~200-character limit. */
    private const CHUNK_CHARS = 170;

    /**
     * Synthesise a single speakable chunk to MP3.
     *
     * @return string|null MP3 bytes, or null when synthesis is unavailable.
     */
    public function synthesize(string $text, string $lang = 'ar'): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || mb_strlen($text) > self::CHUNK_CHARS + 40) {
            return null;
        }

        return $this->fetch($text, $lang);
    }

    /**
     * Synthesise Arabic text to MP3. Only single-chunk text can be returned as
     * one stream; longer text must be fetched chunk-by-chunk by the caller.
     *
     * @return string|null MP3 bytes, or null when synthesis is unavailable.
     */
    public function speak(string $text, string $lang = 'ar'): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        $chunks = $this->chunk($text);

        return count($chunks) === 1 ? $this->fetch($chunks[0], $lang) : null;
    }

    /**
     * Split text into speakable chunks on word boundaries, keeping each chunk
     * under the upstream limit so no request is rejected.
     *
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $chunks = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($candidate) > self::CHUNK_CHARS && $current !== '') {
                $chunks[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function fetch(string $text, string $lang): ?string
    {
        $base = rtrim((string) config('platform.tts.base_url'), '/');

        // The same phrases recur constantly (feedback, lesson openers), so cache
        // the audio to cut both upstream cost and playback latency.
        $key = 'tts:' . $lang . ':' . md5($text);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $res = Http::timeout((int) config('platform.tts.timeout', 20))
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($base, [
                    'ie' => 'UTF-8',
                    'client' => config('platform.tts.client', 'tw-ob'),
                    'tl' => $lang,
                    'q' => $text,
                ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $res->ok()) {
            return null;
        }

        $body = $res->body();
        // Guard against an HTML error page masquerading as audio.
        if (strlen($body) <= 512) {
            return null;
        }

        Cache::put($key, $body, now()->addDays(30));

        return $body;
    }
}
