<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * Builds multiple-choice questions directly from the stored curriculum, so a
 * learner can self-test the Yemeni national curriculum and see, for every
 * question, the lesson/unit/page it was taken from.
 *
 * Nothing is invented: each question is derived from a real curriculum row
 * (lesson title, unit heading, number-vocabulary point, or an arithmetic
 * expression printed in the lesson). The correct option is always the stored
 * value, and the distractors are drawn from the same grade/subject so they stay
 * plausible without ever competing with the keyed answer.
 */
class QuizBankService
{
    private const AR_DIGITS = ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'];

    private const DIGIT_AR = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    /**
     * @return array{grade: ?Grade, subject: ?Subject, questions: list<array<string, mixed>>}
     */
    public function build(?int $gradeId, ?int $subjectId, ?int $limit = null): array
    {
        $grade = $gradeId ? Grade::find($gradeId) : Grade::orderBy('level')->first();
        $subject = $subjectId ? Subject::find($subjectId) : null;
        if ($subject && (! $grade || $subject->grade_id !== $grade->id)) {
            $grade = $subject->grade;
        }

        $limit = $limit === null ? 12 : max(1, min(50, $limit));

        $lessons = $this->lessonsFor($grade, $subject);
        if ($lessons->isEmpty()) {
            return ['grade' => $grade, 'subject' => $subject, 'questions' => []];
        }

        $questions = [];
        $seen = [];
        foreach ($this->candidateQuestions($grade, $subject, $lessons) as $q) {
            $key = $q['prompt'] . '|' . $q['answer'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $questions[] = $q;
            if (count($questions) >= $limit) {
                break;
            }
        }

        return [
            'grade' => $grade,
            'subject' => $subject,
            'questions' => array_values($questions),
        ];
    }

    /**
     * Candidate questions, ordered so the richest (arithmetic, vocabulary) come
     * first and the page count keeps the bank varied across lessons.
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return list<array<string, mixed>>
     */
    private function candidateQuestions(?Grade $grade, ?Subject $subject, Collection $lessons): array
    {
        $titles = $lessons->pluck('title')
            ->filter(fn ($t) => $this->isCleanTitle((string) $t) && $this->isCleanText((string) $t))
            ->unique()->values()->all();
        $units = $this->unitHeadings($lessons);
        $questions = [];

        foreach ($lessons as $lesson) {
            $meta = $this->lessonMeta($lesson);

            if ($arithmetic = $this->arithmeticQuestion($lesson, $meta, $grade, $subject)) {
                $questions[] = $arithmetic;
            }
            if ($numberWord = $this->numberWordQuestion($lesson, $meta, $titles)) {
                $questions[] = $numberWord;
            }
            // Title/subject/unit questions all quote the lesson title, so they
            // are only sound when the stored title is a real, readable title.
            if ($this->isCleanTitle((string) $lesson->title) && $this->isCleanText((string) $lesson->title)) {
                $questions[] = $this->subjectQuestion($lesson, $meta, $grade, $subject);
                if ($unit = $this->unitQuestion($lesson, $meta, $units)) {
                    $questions[] = $unit;
                }
                $questions[] = $this->titleQuestion($lesson, $meta, $titles);
            }
        }

        return $questions;
    }

    /** Reject page-range placeholders and empty strings used as lesson titles. */
    private function isCleanTitle(string $title): bool
    {
        $t = trim($title);
        if (mb_strlen($t) < 2 || mb_strlen($t) > 90) {
            return false;
        }
        if (preg_match('/^صفحات\s/u', $t)) {
            return false;
        }

        return ! preg_match('/^\d+\s*[-–]\s*\d+$/u', $t);
    }

    /**
     * Reject OCR garble used as an option or unit name: a run of the same
     * single letter (e.g. "فمم م م ممم") marks a mis-read, so it never becomes
     * a quiz option.
     */
    private function isCleanText(string $text): bool
    {
        $t = trim($text);
        if (mb_strlen($t) < 2 || mb_strlen($t) > 120) {
            return false;
        }
        // Three or more identical Arabic letters separated by whitespace.
        return ! preg_match('/(\p{Arabic})\s+\1\s+\1/u', $t);
    }

    /** @param Collection<int, Lesson> $lessons */
    private function lessonsFor(?Grade $grade, ?Subject $subject): Collection
    {
        if (! $grade) {
            return collect();
        }

        $query = Lesson::query()
            ->whereHas('textbook.subject', function ($q) use ($grade, $subject) {
                $q->where('grade_id', $grade->id);
                if ($subject) {
                    $q->where('id', $subject->id);
                }
            })
            ->with(['textbook.subject', 'sections'])
            ->orderBy('textbook_id')
            ->orderBy('sort_order');

        return $query->get();
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     * @return array<string, string> lesson id => unit name
     */
    private function unitHeadings(Collection $lessons): array
    {
        $out = [];
        foreach ($lessons as $lesson) {
            // Only a stored "الوحدة: …" heading/point names a real unit; the
            // lesson's own heading is not a unit, so it is never used here.
            foreach ($lesson->sections as $section) {
                if (preg_match('/^الوحدة[:\s]+(.+)$/u', trim((string) $section->content), $m)) {
                    // Drop the generated "— مادة …" suffix so the unit reads as
                    // a plain unit name.
                    $unit = preg_split('/\s*—\s*مادة\s/u', $m[1])[0];
                    $unit = trim($unit, " .\t\n");
                    // OCR sometimes leaves a garbled heading; skip those rather
                    // than offer a nonsense distractor.
                    if ($this->isCleanText($unit)) {
                        $out[$lesson->id] = $unit;
                    }
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonMeta(Lesson $lesson): array
    {
        $subject = $lesson->textbook?->subject;

        return [
            'lesson_id' => $lesson->id,
            'lesson_title' => $lesson->title,
            'textbook_id' => $lesson->textbook_id,
            'textbook_title' => $lesson->textbook?->title,
            'subject' => $subject?->name,
            'grade' => $subject?->grade?->name,
            'page' => $lesson->page_from,
        ];
    }

    private function evidence(array $meta, string $excerpt): string
    {
        $parts = [];
        if (! empty($meta['subject'])) {
            $parts[] = 'مادة ' . $meta['subject'];
        }
        if (! empty($meta['grade'])) {
            $parts[] = $meta['grade'];
        }
        if (! empty($meta['page'])) {
            $parts[] = 'صفحة ' . $meta['page'];
        }
        $head = implode(' — ', $parts);

        return ($head !== '' ? $head . ' — ' : '') . 'نص المنهج: «' . $excerpt . '»';
    }

    /**
     * A numeric MCQ from an arithmetic expression printed in the lesson (e.g.
     * "٢ + ٣ = ٥"), verified by real evaluation so the key is always correct.
     */
    private function arithmeticQuestion(Lesson $lesson, array $meta, ?Grade $grade, ?Subject $subject): ?array
    {
        foreach ($lesson->sections as $section) {
            if (! in_array($section->type, ['example', 'point', 'vocab'], true)) {
                continue;
            }
            $text = (string) $section->content;
            if (! preg_match('/([٠-٩0-9]+)\s*([+\-×xX*])\s*([٠-٩0-9]+)\s*=\s*([٠-٩0-9]+)/u', $text, $m)) {
                continue;
            }
            $a = (int) $this->toWestern($m[1]);
            $b = (int) $this->toWestern($m[3]);
            $shown = (int) $this->toWestern($m[4]);
            $expected = $this->applyOp($a, $m[2], $b);
            if ($expected !== $shown) {
                continue; // never key an incorrect equation
            }
            $answer = $this->toArabic((string) $expected);
            $distractors = array_values(array_filter([
                $this->toArabic((string) max(0, $expected - 1)),
                $this->toArabic((string) ($expected + 1)),
                $this->toArabic((string) ($expected + 2)),
            ], fn ($v) => $v !== $answer));

            return [
                'type' => 'mcq',
                'prompt' => "ناتج {$this->toArabic((string) $a)} {$m[2]} {$this->toArabic((string) $b)} يساوي:",
                'answer' => $answer,
                'options' => $this->options($answer, $distractors),
                'evidence' => $this->evidence($meta, $text),
                'source' => $meta + ['excerpt' => $text],
            ];
        }

        return null;
    }

    /**
     * "أي عدد يُكتب «٦»؟" built from a stored number-vocabulary point.
     */
    private function numberWordQuestion(Lesson $lesson, array $meta, array $titles): ?array
    {
        $words = [
            'واحد' => '١', 'اثنان' => '٢', 'ثلاثة' => '٣', 'أربعة' => '٤', 'خمسة' => '٥',
            'ستة' => '٦', 'سبعة' => '٧', 'ثمانية' => '٨', 'تسعة' => '٩', 'عشرة' => '١٠',
        ];
        foreach ($lesson->sections as $section) {
            $text = (string) $section->content;
            foreach ($words as $word => $digit) {
                if (! str_contains($text, "يُكتب $digit") && ! str_contains($text, "يُكتب هكذا: $digit")) {
                    continue;
                }
                $answer = $word;
                $distractors = collect($words)->keys()->reject(fn ($w) => $w === $word)->shuffle()->take(3)->all();

                return [
                    'type' => 'mcq',
                    'prompt' => "أي كلمة تدل على العدد {$digit}؟",
                    'answer' => $answer,
                    'options' => $this->options($answer, $distractors),
                    'evidence' => $this->evidence($meta, $text),
                    'source' => $meta + ['excerpt' => $text],
                ];
            }
        }

        return null;
    }

    /** "ما عنوان هذا الدرس؟" — answer is the stored lesson title. */
    private function titleQuestion(Lesson $lesson, array $meta, array $titles): array
    {
        $answer = $lesson->title;
        $distractors = collect($titles)->reject(fn ($t) => $t === $answer)->shuffle()->take(3)->all();

        return [
            'type' => 'mcq',
            'prompt' => 'ما عنوان هذا الدرس؟',
            'answer' => $answer,
            'options' => $this->options($answer, $distractors, 'لا شيء'),
            'evidence' => $this->evidence($meta, 'درس: ' . $answer),
            'source' => $meta + ['excerpt' => 'درس: ' . $answer],
        ];
    }

    /** "في أي مادة يُدرَّس درس «…»؟" — answer is the subject name. */
    private function subjectQuestion(Lesson $lesson, array $meta, ?Grade $grade, ?Subject $subject): array
    {
        $answer = (string) $meta['subject'];
        $pool = $grade
            ? $grade->subjects()->pluck('name')->all()
            : Subject::query()->pluck('name')->unique()->all();
        $distractors = collect($pool)->reject(fn ($n) => $n === $answer)->shuffle()->take(3)->all();

        return [
            'type' => 'mcq',
            'prompt' => "في أي مادة يُدرَّس درس «{$lesson->title}»؟",
            'answer' => $answer,
            'options' => $this->options($answer, $distractors),
            'evidence' => $this->evidence($meta, 'درس: ' . $lesson->title),
            'source' => $meta + ['excerpt' => 'درس: ' . $lesson->title],
        ];
    }

    /** "إلى أي وحدة ينتمي درس «…»؟" — answer is the stored unit heading. */
    private function unitQuestion(Lesson $lesson, array $meta, array $units): ?array
    {
        if (! isset($units[$lesson->id])) {
            return null;
        }
        $answer = $units[$lesson->id];
        $distractors = collect($units)
            ->reject(fn ($u) => $u === $answer)
            ->unique()
            ->shuffle()
            ->take(3)
            ->values()
            ->all();
        if (count($distractors) < 2) {
            return null;
        }

        return [
            'type' => 'mcq',
            'prompt' => "إلى أي وحدة ينتمي درس «{$lesson->title}»؟",
            'answer' => $answer,
            'options' => $this->options($answer, $distractors),
            'evidence' => $this->evidence($meta, 'الوحدة: ' . $answer),
            'source' => $meta + ['excerpt' => 'الوحدة: ' . $answer],
        ];
    }

    /**
     * @param  list<string>  $distractors
     * @return list<array{text: string, is_correct: bool}>
     */
    private function options(string $answer, array $distractors, ?string $filler = null): array
    {
        $opts = [$answer];
        foreach ($distractors as $d) {
            if ($d !== '' && $this->isCleanText((string) $d) && ! in_array($d, $opts, true)) {
                $opts[] = $d;
            }
        }
        // An MCQ must always offer a real choice; fall back to a neutral
        // distractor when too few clean ones were available.
        foreach ([$filler, 'لا شيء', 'لا أعرف'] as $fallback) {
            if (count($opts) >= 2) {
                break;
            }
            if ($fallback !== null && ! in_array($fallback, $opts, true)) {
                $opts[] = $fallback;
            }
        }
        $opts = array_slice($opts, 0, 4);
        shuffle($opts);

        return array_map(fn ($t) => ['text' => $t, 'is_correct' => $t === $answer], $opts);
    }

    private function applyOp(int $a, string $op, int $b): int
    {
        return match (strtolower($op)) {
            '+' => $a + $b,
            '-', '−' => $a - $b,
            default => $a * $b,
        };
    }

    private function toWestern(string $value): string
    {
        return strtr($value, self::DIGIT_AR);
    }

    private function toArabic(string $value): string
    {
        return strtr($value, self::AR_DIGITS);
    }
}
