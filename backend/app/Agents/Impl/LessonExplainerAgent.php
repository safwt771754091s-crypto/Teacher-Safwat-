<?php

namespace App\Agents\Impl;

use App\Agents\AgentContext;
use App\Agents\AgentResult;
use App\Agents\Contracts\Agent;
use App\Models\Lesson;
use App\Services\AiTeacherService;

/**
 * Explains a lesson from its approved textbook source, in the language level of
 * the student, and answers questions grounded in the same source.
 */
class LessonExplainerAgent implements Agent
{
    public function __construct(private AiTeacherService $ai)
    {
    }

    public function key(): string
    {
        return 'lesson-explainer';
    }

    public function name(): string
    {
        return 'وكيل شرح الدروس';
    }

    public function purpose(): string
    {
        return 'يشرح الدرس من الكتاب المدرسي بلغة مناسبة لعمر الطالب ويجيب عن أسئلته.';
    }

    public function offlineCapable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return ['explain', 'summarize', 'qa'];
    }

    public function run(AgentContext $context): AgentResult
    {
        $lesson = $context->subject;
        if (! $lesson instanceof Lesson) {
            return AgentResult::error('هذا الوكيل يحتاج درسًا.');
        }

        $explain = $this->ai->explain(
            $lesson,
            $context->param('question'),
            $context->user,
            (int) $context->param('reading_age', 8),
        );

        if (! empty($explain['needs_content'])) {
            return AgentResult::needsContent('الدرس لا يحتوي محتوى معتمدًا بعد.', ['explanation' => $explain]);
        }

        return AgentResult::ok(
            ['explanation' => $explain],
            count($explain['explanation'] ?? []),
            offline: ($explain['provider'] ?? 'source') === 'source',
            provider: $explain['provider'] ?? 'source',
        );
    }
}
