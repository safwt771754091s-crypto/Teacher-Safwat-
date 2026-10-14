<?php

namespace App\Services;

/**
 * Procedural math question generator for the primary/secondary games.
 *
 * Ported from the Yemeni curriculum reference (alfaeq-yemen mathBank) so games
 * ship with a large, varied, always-answerable question set instead of a handful
 * of hard-coded items. Every generated question carries its own correct answer,
 * so correctness never depends on a language model.
 *
 * Output shape matches the SPA game config: { q, options[], answer }.
 */
class MathQuestionBank
{
    /** Grade bands and their numeric range for arithmetic. */
    private const BANDS = [
        'g1_3' => 10,
        'g4_6' => 30,
        'g7_9' => 50,
        'sec' => 100,
    ];

    public function bandForGrade(int $grade): string
    {
        if ($grade <= 3) {
            return 'g1_3';
        }
        if ($grade <= 6) {
            return 'g4_6';
        }
        if ($grade <= 9) {
            return 'g7_9';
        }

        return 'sec';
    }

    /**
     * Fixed question types for the named primary games, so a game titled
     * "fast addition" actually serves addition items (not an arbitrary mix).
     */
    private const THEMES = [
        'addition' => ['addition'],
        'subtraction' => ['subtraction'],
        'multiplication' => ['multiplication'],
        'division' => ['division'],
        'missing' => ['missing'],
        'fraction' => ['fraction'],
        'geometry' => ['geometry'],
        'squares' => ['squares'],
        'equation' => ['equation'],
        'exponent' => ['exponent'],
        'tables' => ['multiplication', 'fraction'],
        'numbers' => ['arithmetic', 'missing', 'squares'],
    ];

    /**
     * @return array<int, array{q: string, options: string[], answer: string}>
     */
    public function pool(string $band, int $count = 12): array
    {
        return $this->poolFor($band, 'mixed', $count);
    }

    /**
     * Build a pool for a named theme (see self::THEMES), or the band's default
     * mix when $theme is 'mixed'.
     *
     * @return array<int, array{q: string, options: string[], answer: string}>
     */
    public function poolFor(string $band, string $theme, int $count = 12): array
    {
        $range = self::BANDS[$band] ?? 30;
        $generators = ($theme !== 'mixed' && isset(self::THEMES[$theme]))
            ? self::THEMES[$theme]
            : match ($band) {
                'sec' => ['arithmetic', 'squares', 'equation', 'exponent'],
                'g7_9' => ['arithmetic', 'fraction', 'geometry', 'squares'],
                'g4_6' => ['arithmetic', 'fraction', 'geometry'],
                default => ['arithmetic', 'geometry'],
            };

        $out = [];
        $guard = 0;
        while (count($out) < $count && $guard++ < $count * 40) {
            $q = $this->{$generators[array_rand($generators)]}($range);
            if ($q !== null) {
                $out[] = $this->toArabicNumerals($q);
            }
        }

        return $out;
    }

    /**
     * Present numbers with Arabic-Indic digits (٠-٩) as Yemeni primary pupils
     * read them. Applied uniformly to the question, its options and the answer,
     * so the SPA's string comparison still matches.
     *
     * @param  array{q: string, options: string[], answer: string}  $q
     * @return array{q: string, options: string[], answer: string}
     */
    private function toArabicNumerals(array $q): array
    {
        $map = ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'];

        return [
            'q' => strtr($q['q'], $map),
            'options' => array_map(fn ($o) => strtr((string) $o, $map), $q['options']),
            'answer' => strtr($q['answer'], $map),
        ];
    }

    private function arithmetic(int $range): ?array
    {
        $op = ['+', '−', '×', '÷'][random_int(0, 3)];

        return $this->arithmeticOp($op, $range);
    }

    private function addition(int $range): ?array
    {
        return $this->arithmeticOp('+', $range);
    }

    private function subtraction(int $range): ?array
    {
        return $this->arithmeticOp('−', $range);
    }

    private function multiplication(int $range): ?array
    {
        return $this->arithmeticOp('×', $range);
    }

    private function division(int $range): ?array
    {
        return $this->arithmeticOp('÷', $range);
    }

    private function arithmeticOp(string $op, int $range): ?array
    {
        if ($op === '+') {
            $a = random_int(1, $range);
            $b = random_int(1, $range);
            $ans = $a + $b;
        } elseif ($op === '−') {
            $a = random_int(1, $range + 20);
            $b = random_int(1, $a);
            $ans = $a - $b;
        } elseif ($op === '×') {
            $a = random_int(2, max(5, intdiv($range, 2)));
            $b = random_int(2, 12);
            $ans = $a * $b;
        } else {
            $b = random_int(2, 12);
            $ans = random_int(2, 12);
            $a = $b * $ans;
        }

        return $this->mcq("{$a} {$op} {$b}", $ans, [
            $ans + random_int(1, 5),
            abs($ans - random_int(1, 5)),
            $ans + random_int(6, 10),
        ]);
    }

    /** Complete the sequence: 3, 4, …, 6 → 5. */
    private function missing(int $range): ?array
    {
        $start = random_int(1, max(2, $range - 4));
        $step = random_int(1, 3);
        $answer = $start + $step;
        $last = $answer + $step;

        return $this->mcq("أكمل النمط: {$start} ، {$answer} ، … ، {$last}", $answer, [
            $answer + 1, max(0, $answer - 1), $last,
        ]);
    }

    private function fraction(int $range): ?array
    {
        $den = [2, 3, 4, 5, 6, 8][random_int(0, 5)];
        $num = random_int(1, $den - 1);

        return match (random_int(0, 2)) {
            0 => $this->mcq("ما هو البسط في الكسر {$num}/{$den}؟", $num, [$den, $num + 1, $den - $num]),
            1 => (function () use ($num, $den) {
                $pct = (int) round($num / $den * 100);

                return $this->mcq("الكسر {$num}/{$den} يمثّل كم بالمئة تقريباً؟", "{$pct}%", [($pct + 10) . '%', max(0, $pct - 15) . '%', ($pct + 25) . '%']);
            })(),
            default => $this->mcq("ما مقلوب الكسر {$num}/{$den}؟", "{$den}/{$num}", ["{$num}/{$num}", "{$den}/{$den}", ($num + 1) . "/{$den}"]),
        };
    }

    private function geometry(int $range): ?array
    {
        $shapes = [
            ['المثلث', 3], ['المربع', 4], ['الخماسي', 5],
            ['السداسي', 6], ['الثماني', 8], ['العشاري', 10],
        ];
        [$name, $sides] = $shapes[array_rand($shapes)];

        return $this->mcq("كم عدد أضلاع الشكل {$name}؟", $sides, [$sides + 1, $sides - 1, $sides + 2]);
    }

    private function squares(int $range): ?array
    {
        $n = random_int(2, 15);

        return random_int(0, 1)
            ? $this->mcq("مربع العدد {$n}", $n * $n, [$n * $n + $n, $n * 2, $n * $n - $n])
            : $this->mcq("الجذر التربيعي للعدد " . ($n * $n), $n, [$n + 1, $n - 1, intdiv($n * $n, 2)]);
    }

    private function equation(int $range): ?array
    {
        $x = random_int(2, 10);
        $c = random_int(1, 15);

        return $this->mcq("حل المعادلة: س + {$c} = " . ($x + $c), $x, [$x + 1, $x - 1, $x + $c]);
    }

    private function exponent(int $range): ?array
    {
        $a = [2, 3, 5][random_int(0, 2)];
        $n = random_int(2, 3);
        $ans = $a ** $n;

        return $this->mcq("{$a}^{$n}", $ans, [$a * $n, $ans + $a, $a + $n]);
    }

    /**
     * Build a multiple-choice item with three distractors that never equal the
     * answer and never repeat.
     *
     * @param  array<int, int|string>  $distractors
     */
    private function mcq(string $question, int|string $answer, array $distractors): ?array
    {
        $answer = (string) $answer;
        $unique = [];
        foreach ($distractors as $d) {
            $d = (string) $d;
            if ($d !== $answer && ! in_array($d, $unique, true)) {
                $unique[] = $d;
            }
        }
        $k = 1;
        while (count($unique) < 3 && $k <= 30) {
            $cand = is_numeric($answer) ? (string) ((int) $answer + $k) : $answer . $k;
            if ($cand !== $answer && ! in_array($cand, $unique, true)) {
                $unique[] = $cand;
            }
            $k++;
        }

        $options = array_slice([$answer, ...$unique], 0, 4);
        shuffle($options);

        if (count($options) < 2) {
            return null;
        }

        return ['q' => $question, 'options' => $options, 'answer' => $answer];
    }
}
