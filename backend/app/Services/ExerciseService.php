<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Exercise;
use App\Models\LearningProgress;
use App\Models\Lesson;
use App\Models\StudentProfile;

/**
 * Grades exercises and produces step-by-step solutions.
 *
 * Correctness is decided by deterministic rules/reference answers first —
 * arithmetic is verified by evaluating the expression, and reference answers
 * are compared with Arabic-aware normalization. The AI only explains the
 * steps; it never decides correctness on its own.
 */
class ExerciseService
{
    public function __construct(private AiTeacherService $ai)
    {
    }

    /**
     * @return array{is_correct: bool, feedback: string, steps: string[], expected?: string}
     */
    public function grade(Exercise $exercise, ?string $given, bool $hintUsed = false): array
    {
        $exercise->loadMissing('options');
        $given = $this->normalizeAnswer((string) $given);

        if ($exercise->type === 'mcq' || $exercise->type === 'true_false') {
            return $this->gradeChoice($exercise, $given);
        }

        if ($exercise->type === 'numeric' || $exercise->type === 'step_by_step') {
            return $this->gradeNumericOrSteps($exercise, $given);
        }

        return $this->gradeReference($exercise, $given);
    }

    private function gradeChoice(Exercise $exercise, string $given): array
    {
        $correct = $exercise->options->firstWhere('is_correct', true);
        $givenOption = $exercise->options->first(function ($o) use ($given) {
            return $this->normalizeAnswer($o->label ?? '') === $given
                || $this->normalizeAnswer($o->text) === $given;
        });

        $isCorrect = $correct && $givenOption && $correct->id === $givenOption->id;

        return [
            'is_correct' => (bool) $isCorrect,
            'feedback' => $isCorrect ? 'أحسنت! الإجابة صحيحة.' : 'الإجابة غير صحيحة، حاول مرة أخرى أو اطلب تلميحًا.',
            'steps' => $this->stepsFor($exercise),
            'expected' => $correct?->text,
        ];
    }

    private function gradeNumericOrSteps(Exercise $exercise, string $given): array
    {
        $answer = $this->extractExpression((string) $exercise->answer);
        $givenExpr = $this->extractExpression($given);

        if ($answer !== null && $givenExpr !== null) {
            $isCorrect = abs($answer - $givenExpr) < 0.0001;
            return [
                'is_correct' => $isCorrect,
                'feedback' => $isCorrect ? 'أحسنت! الناتج صحيح.' : 'الناتج غير صحيح. راجع خطوات الحل ثم أعد المحاولة.',
                'steps' => $this->stepsFor($exercise),
                'expected' => (string) $exercise->answer,
            ];
        }

        return $this->gradeReference($exercise, $given);
    }

    private function gradeReference(Exercise $exercise, string $given): array
    {
        $expected = $this->normalizeAnswer((string) $exercise->answer);
        $isCorrect = $expected !== '' && $given !== '' && $expected === $given;

        return [
            'is_correct' => $isCorrect,
            'feedback' => $isCorrect ? 'أحسنت! الإجابة صحيحة.' : 'الإجابة لا تطابق إجابة الكتاب. راجع الدرس ثم أعد المحاولة.',
            'steps' => $this->stepsFor($exercise),
            'expected' => (string) $exercise->answer,
        ];
    }

    /**
     * Return the reference solution steps (never AI-decided correctness).
     */
    public function stepsFor(Exercise $exercise): array
    {
        if (is_array($exercise->solution_steps) && ! empty($exercise->solution_steps)) {
            return $exercise->solution_steps;
        }
        return [];
    }

    public function recordAttempt(StudentProfile $student, Exercise $exercise, ?string $given, array $result, int $durationSeconds = 0, bool $hintUsed = false): Attempt
    {
        $attempt = Attempt::create([
            'student_profile_id' => $student->id,
            'exercise_id' => $exercise->id,
            'answer' => $given,
            'is_correct' => $result['is_correct'],
            'score' => $result['is_correct'] ? 100 : 0,
            'solution_steps' => $result['steps'],
            'hint_used' => $hintUsed,
            'duration_seconds' => $durationSeconds,
        ]);

        $this->recomputeProgress($student, $exercise->lesson_id);

        return $attempt;
    }

    /**
     * Recompute lesson mastery from real attempts (used by learner dashboards
     * and the "weak points" review list).
     */
    public function recomputeProgress(StudentProfile $student, int $lessonId): void
    {
        $exerciseIds = Exercise::where('lesson_id', $lessonId)->pluck('id');
        if ($exerciseIds->isEmpty()) {
            return;
        }
        $attempts = Attempt::where('student_profile_id', $student->id)
            ->whereIn('exercise_id', $exerciseIds)
            ->get();

        $total = $exerciseIds->count();
        $mastered = $attempts->where('is_correct', true)->unique('exercise_id')->count();
        $percent = (int) round(($mastered / max($total, 1)) * 100);

        $progress = LearningProgress::firstOrNew([
            'student_profile_id' => $student->id,
            'lesson_id' => $lessonId,
        ]);
        $progress->status = $percent >= 100 ? 'mastered' : ($attempts->isNotEmpty() ? 'in_progress' : 'not_started');
        $progress->mastery_percent = $percent;
        $progress->last_activity_at = now();
        $progress->save();
    }

    /**
     * "Weak points": exercises the student keeps getting wrong.
     */
    public function weakPoints(StudentProfile $student, int $limit = 10)
    {
        return Attempt::with('exercise.lesson')
            ->where('student_profile_id', $student->id)
            ->where('is_correct', false)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('exercise_id')
            ->sortByDesc(fn ($rows) => $rows->count())
            ->take($limit)
            ->map(fn ($rows) => $rows->first());
    }

    private function extractExpression(string $value): ?float
    {
        $value = $this->normalizeDigits(trim($value));
        $value = str_replace(['×', 'x', 'X', '÷', '\\'], ['*', '*', '*', '/', '/'], $value);
        if ($value === '' || ! preg_match('~^[-+*/().\d\s]+$~', $value)) {
            return null;
        }
        try {
            if (! preg_match('/[+\-*\/]/', $value)) {
                return (float) $value;
            }
            return (float) $this->evalSimple($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tiny safe evaluator for + - * / and parentheses (no eval()).
     */
    private function evalSimple(string $expr): float
    {
        $tokens = preg_split('/\s*/', $expr, -1, PREG_SPLIT_NO_EMPTY);
        $stack = [];
        $out = [];
        $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
        $ops = [];
        foreach ($tokens as $t) {
            if (is_numeric($t)) {
                $out[] = (float) $t;
            } elseif ($t === '(') {
                $ops[] = $t;
            } elseif ($t === ')') {
                while (end($ops) !== '(') {
                    $out[] = array_pop($ops);
                }
                array_pop($ops);
            } else {
                while ($ops && end($ops) !== '(' && $prec[end($ops)] >= $prec[$t]) {
                    $out[] = array_pop($ops);
                }
                $ops[] = $t;
            }
        }
        while ($ops) {
            $out[] = array_pop($ops);
        }
        foreach ($out as $t) {
            if (is_float($t)) {
                $stack[] = $t;
            } else {
                $b = array_pop($stack);
                $a = array_pop($stack);
                $stack[] = match ($t) {
                    '+' => $a + $b,
                    '-' => $a - $b,
                    '*' => $a * $b,
                    '/' => $b == 0 ? throw new \RuntimeException('div0') : $a / $b,
                };
            }
        }
        return (float) end($stack);
    }

    public function normalizeAnswer(string $value): string
    {
        $value = $this->normalizeDigits($value);
        $value = preg_replace('/[إأآا]/u', 'ا', $value);
        $value = str_replace('ة', 'ه', $value);
        $value = preg_replace('/[ىي]/u', 'ي', $value);
        $value = preg_replace('/[\x{064B}-\x{0652}]/u', '', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    }

    private function normalizeDigits(string $value): string
    {
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        return str_replace($arabic, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $value);
    }
}
