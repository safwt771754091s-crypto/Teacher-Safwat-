<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\AudioService;
use Illuminate\Http\Request;

class AudioController extends Controller
{
    public function __construct(private AudioService $audio)
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
}
