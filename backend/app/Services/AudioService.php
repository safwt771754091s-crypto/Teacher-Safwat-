<?php

namespace App\Services;

use App\Models\AudioAsset;
use App\Models\Lesson;
use Illuminate\Support\Facades\Http;

/**
 * Turns lesson text into speech.
 *
 * If a TTS provider is configured we use it; otherwise the platform returns the
 * lesson text with a `speak` directive and the client uses the on-device
 * SpeechSynthesis (works offline and needs no per-character cost). Either way
 * the audio text is exactly the approved lesson content.
 */
class AudioService
{
    public function __construct(private AiTeacherService $ai, private TtsService $tts)
    {
    }

    public function lessonNarration(Lesson $lesson, ?string $voice = null, float $speed = 1.0): array
    {
        $ctx = $this->ai->retrieveContext($lesson);
        $text = implode("\n", $ctx['points']);

        $asset = AudioAsset::create([
            'lesson_id' => $lesson->id,
            'text' => $text,
            'voice' => $voice ?? 'ar-default',
            'lang' => 'ar',
            'status' => 'ready',
        ]);

        return [
            'asset_id' => $asset->id,
            'mode' => 'client_synthesis',
            'provider' => config('platform.tts.enabled') ? 'server' : 'device',
            // Ordered speakable parts: the client reads them in sequence with the
            // device voice, or fetches each as MP3 from the TTS endpoint when the
            // device has no Arabic voice.
            'chunks' => $this->tts->chunk($text),
            'text' => $text,
            'speed' => $speed,
            'duration_hint' => $this->estimateSeconds($text, $speed),
            'pages' => $ctx['pages'],
        ];
    }

    private function estimateSeconds(string $text, float $speed): int
    {
        $words = max(1, str_word_count($text) ?: (int) (mb_strlen($text) / 5));
        $seconds = (int) round(($words / 2.5) / max($speed, 0.1)); // ~150 wpm
        return max(1, $seconds);
    }
}
