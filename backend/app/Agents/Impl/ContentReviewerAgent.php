<?php

namespace App\Agents\Impl;

use App\Agents\AgentContext;
use App\Agents\AgentResult;
use App\Agents\Contracts\Agent;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Textbook;

/**
 * Reviews extracted textbook content and draft exercises for quality problems
 * (empty questions, missing options, unanswered MCQs) and flags them for the
 * human reviewer. It only reports issues; it never rewrites content.
 */
class ContentReviewerAgent implements Agent
{
    public function key(): string
    {
        return 'content-reviewer';
    }

    public function name(): string
    {
        return 'وكيل مراجعة المحتوى';
    }

    public function purpose(): string
    {
        return 'يفحص الدروس والتمارين المستخرجة ويكشف النواقص قبل اعتمادها.';
    }

    public function offlineCapable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return ['quality-check', 'flag-for-review'];
    }

    public function run(AgentContext $context): AgentResult
    {
        $subject = $context->subject;
        $limit = (int) $context->param('limit', 50);

        $issues = [];
        if ($subject instanceof Lesson) {
            $issues = $this->reviewLesson($subject, $limit);
        } elseif ($subject instanceof Textbook) {
            foreach ($subject->lessons()->take($limit)->get() as $lesson) {
                $issues = array_merge($issues, $this->reviewLesson($lesson, $limit));
            }
        } elseif ($subject instanceof Exercise) {
            if ($issue = $this->reviewExercise($subject)) {
                $issues[] = $issue;
            }
        } else {
            // No subject: sweep the newest draft lessons.
            foreach (Lesson::where('status', 'draft')->latest()->take($limit)->get() as $lesson) {
                $issues = array_merge($issues, $this->reviewLesson($lesson, $limit));
            }
        }

        return AgentResult::ok(['issues' => $issues], count($issues), offline: true);
    }

    private function reviewLesson(Lesson $lesson, int $limit): array
    {
        $issues = [];
        if ($lesson->sections()->count() === 0) {
            $issues[] = ['lesson_id' => $lesson->id, 'type' => 'empty_lesson', 'detail' => 'الدرس لا يحتوي أي عناوين أو نقاط.'];
        }
        foreach ($lesson->exercises()->where('is_draft', true)->take($limit)->get() as $exercise) {
            if ($issue = $this->reviewExercise($exercise)) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    private function reviewExercise(Exercise $exercise): ?array
    {
        $question = trim((string) $exercise->question);
        if (mb_strlen($question) < 4) {
            return ['exercise_id' => $exercise->id, 'type' => 'empty_question', 'detail' => 'نص السؤال غير واضح أو فارغ.'];
        }
        if ($exercise->type === 'mcq' && $exercise->options()->count() < 2) {
            return ['exercise_id' => $exercise->id, 'type' => 'missing_options', 'detail' => 'سؤال اختيار من متعدد بلا خيارات كافية.'];
        }
        if ($exercise->type === 'mcq' && $exercise->answer === null && ! $exercise->options()->where('is_correct', true)->exists()) {
            return ['exercise_id' => $exercise->id, 'type' => 'no_correct_option', 'detail' => 'لم تُحدَّد الإجابة الصحيحة بعد.'];
        }

        return null;
    }
}
