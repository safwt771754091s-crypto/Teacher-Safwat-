<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\AudioService;
use App\Services\TtsService;
use Illuminate\Http\Request;

class AudioController extends Controller
{
    public function __construct(private AudioService $audio, private TtsService $tts)
    {
    }

    public function narrate(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'voice' => ['nullable', 'string', 'max:50'],
            'speed' => ['nullable', 'numeric', 'min:0.5', 'max:2'],
        ]);

        return response()->json($this->audio->lessonNarration(
            $lesson,
            $data['voice'] ?? null,
            (float) ($data['speed'] ?? 1.0),
        ));
    }

    /**
     * Server-side Arabic TTS: returns MP3 bytes for one speakable chunk.
     *
     * Used by clients whose device has no Arabic voice, so the lesson can still
     * be heard. Returns 204 when synthesis is disabled or unavailable, letting
     * the client keep its device-voice behaviour instead of failing.
     */
    public function speech(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:400'],
            'lang' => ['nullable', 'string', 'max:8'],
        ]);

        abort_unless((bool) config('platform.tts.enabled'), 404);

        $audio = $this->tts->synthesize($data['text'], $data['lang'] ?? 'ar');
        if ($audio === null) {
            return response()->noContent();
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen($audio),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
