<?php

namespace App\Agents\Impl;

use App\Agents\AgentContext;
use App\Agents\AgentResult;
use App\Agents\Contracts\Agent;
use App\Models\Exercise;
use App\Services\AiTeacherService;

/**
 * Solves textbook exercises and explains the steps so a student understands the
 * method. Numeric answers are computed deterministically; textual answers are
 * retrieved from the lesson source. It never invents an answer.
 */
class ExerciseSolverAgent implements Agent
{
    public function __construct(private AiTeacherService $ai)
    {
    }

    public function key(): string
    {
        return 'exercise-solver';
    }

    public function name(): string
    {
        return 'وكيل حل التمارين';
    }

    public function purpose(): string
    {
        return 'يحل نماذج تمارين الكتاب خطوة بخطوة ويشرح الطريقة للطالب.';
    }

    public function offlineCapable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return ['solve', 'step-by-step', 'numeric-verify'];
    }

    public function run(AgentContext $context): AgentResult
    {
        $exercise = $context->subject;
        if (! $exercise instanceof Exercise) {
            return AgentResult::error('هذا الوكيل يحتاج تمرينًا.');
        }

        $explain = $this->ai->explainSolution($exercise, $context->user, (int) $context->param('reading_age', 8));

        if (! empty($explain['needs_content'])) {
            return AgentResult::needsContent(
                'لا يوجد محتوى معتمد لهذا الدرس بعد.',
                ['solution' => $explain],
            );
        }

        return AgentResult::ok(
            ['solution' => $explain],
            count($explain['steps'] ?? []),
            offline: ($explain['provider'] ?? 'source') === 'source',
            provider: $explain['provider'] ?? 'source',
        );
    }
}
