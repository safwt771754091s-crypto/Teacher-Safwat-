<?php

namespace App\Agents\Impl;

use App\Agents\AgentContext;
use App\Agents\AgentResult;
use App\Agents\Contracts\Agent;
use App\Models\Lesson;
use App\Services\AudioService;

/**
 * Produces the lesson's narration text. Audio itself is synthesised on the
 * device (offline SpeechSynthesis), so this works with no internet and no
 * per-character cost; the text is exactly the approved lesson content.
 */
class AudioNarratorAgent implements Agent
{
    public function __construct(private AudioService $audio)
    {
    }

    public function key(): string
    {
        return 'audio-narrator';
    }

    public function name(): string
    {
        return 'وكيل التسجيل الصوتي';
    }

    public function purpose(): string
    {
        return 'يجهّز نص سرد الدرس للاستماع، ويعمل بدون إنترنت عبر نطق الجهاز.';
    }

    public function offlineCapable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return ['narration', 'client-tts'];
    }

    public function run(AgentContext $context): AgentResult
    {
        $lesson = $context->subject;
        if (! $lesson instanceof Lesson) {
            return AgentResult::error('هذا الوكيل يحتاج درسًا.');
        }

        $narration = $this->audio->lessonNarration(
            $lesson,
            $context->param('voice'),
            (float) $context->param('speed', 1.0),
        );

        if (trim((string) ($narration['text'] ?? '')) === '') {
            return AgentResult::needsContent('لا يوجد نص معتمد لسرد هذا الدرس.');
        }

        return AgentResult::ok(['narration' => $narration], 1, offline: true);
    }
}
