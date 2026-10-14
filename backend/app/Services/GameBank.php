<?php

namespace App\Services;

use App\Models\Subject;

/**
 * Generates a playable, always-answerable question game for every curriculum
 * subject, so learners get interactive games in all subjects (not just maths).
 *
 * Each question carries its own correct answer and distractor options built
 * from the subject's own lesson titles, so correctness never depends on a
 * language model. Maths/science/numeric subjects get arithmetic-style games;
 * English gets vocabulary/translation games; every other subject gets a
 * curriculum-recall game built from its lesson titles.
 *
 * Output shape matches the SPA game config: { q, options[], answer }.
 */
class GameBank
{
    /** Subjects whose name maps to a game family. */
    public function family(Subject $subject): string
    {
        $name = $subject->name;

        return match (true) {
            str_contains($name, 'إنجليز') => 'english',
            str_contains($name, 'الرياضيات') => 'math',
            str_contains($name, 'علوم') || str_contains($name, 'فيزياء')
                || str_contains($name, 'كيمياء') || str_contains($name, 'أحياء') => 'science',
            str_contains($name, 'حاسوب') => 'computer',
            default => 'recall',
        };
    }

    public function icon(string $family): string
    {
        return match ($family) {
            'english' => 'reading',
            'math' => 'math',
            'science' => 'science',
            'computer' => 'sequence',
            default => 'sorting',
        };
    }

    public function title(Subject $subject, string $family): string
    {
        return match ($family) {
            'english' => 'لعبة مفردات الإنجليزية: ' . $subject->name,
            'math' => 'لعبة الأعداد والعمليات: ' . $subject->name,
            'science' => 'لعبة العلوم التفاعلية: ' . $subject->name,
            'computer' => 'لعبة مهارات الحاسوب: ' . $subject->name,
            default => 'لعبة دروس ' . $subject->name,
        };
    }

    /**
     * @param  array<int, string>  $titles  lesson/unit titles of the subject
     * @return array<int, array{q: string, options: string[], answer: string}>
     */
    public function questions(Subject $subject, array $titles, int $gradeLevel, int $count = 10): array
    {
        $titles = array_values(array_filter(array_map('trim', $titles)));
        if (count($titles) < 2) {
            return [];
        }

        $family = $this->family($subject);
        $out = [];

        if ($family === 'math') {
            $bank = app(MathQuestionBank::class);
            $out = $bank->pool($bank->bandForGrade($gradeLevel), $count);
        } elseif ($family === 'science' || $family === 'computer') {
            $out = $this->recallQuestions($titles, $count);
        } elseif ($family === 'english') {
            $out = $this->englishQuestions($count);
        }

        if (empty($out)) {
            $out = $this->recallQuestions($titles, $count);
        }

        return array_slice($out, 0, $count);
    }

    /**
     * "Which of these is one of the lessons of this subject?" — every option is
     * a real lesson title, so the answer is grounded in the curriculum itself.
     *
     * @param  array<int, string>  $titles
     * @return array<int, array{q: string, options: string[], answer: string}>
     */
    private function recallQuestions(array $titles, int $count): array
    {
        $titles = array_values(array_unique($titles));
        $n = count($titles);
        if ($n < 3) {
            return [];
        }

        $out = [];
        $guard = 0;
        while (count($out) < $count && $guard++ < $count * 20) {
            $answer = $titles[array_rand($titles)];
            $distractors = [];
            while (count($distractors) < 3) {
                $cand = $titles[array_rand($titles)];
                if ($cand !== $answer && ! in_array($cand, $distractors, true)) {
                    $distractors[] = $cand;
                }
            }
            $options = [$answer, ...$distractors];
            shuffle($options);
            $out[] = ['q' => 'أيٌّ من هذه عنوان درس من دروس هذه المادة؟', 'options' => $options, 'answer' => $answer];
        }

        return $out;
    }

    /**
     * English vocabulary game: read the English word, choose its Arabic meaning.
     *
     * @return array<int, array{q: string, options: string[], answer: string}>
     */
    private function englishQuestions(int $count): array
    {
        $glossary = app(EnglishGlossary::class)->all();
        $terms = array_keys($glossary);
        $n = count($terms);
        if ($n < 4) {
            return [];
        }

        $out = [];
        $guard = 0;
        while (count($out) < $count && $guard++ < $count * 20) {
            $term = $terms[array_rand($terms)];
            $answer = $glossary[$term];
            $distractors = [];
            while (count($distractors) < 3) {
                $cand = $glossary[$terms[array_rand($terms)]];
                if ($cand !== $answer && ! in_array($cand, $distractors, true)) {
                    $distractors[] = $cand;
                }
            }
            $options = [$answer, ...$distractors];
            shuffle($options);
            $out[] = ['q' => 'ما معنى الكلمة الإنجليزية «' . $term . '»؟', 'options' => $options, 'answer' => $answer];
        }

        return $out;
    }
}
