<?php

namespace Tests\Unit;

use App\Services\MathQuestionBank;
use Tests\TestCase;

class MathQuestionBankTest extends TestCase
{
    public function test_band_for_grade_maps_each_stage(): void
    {
        $bank = new MathQuestionBank();

        $this->assertSame('g1_3', $bank->bandForGrade(1));
        $this->assertSame('g1_3', $bank->bandForGrade(3));
        $this->assertSame('g4_6', $bank->bandForGrade(4));
        $this->assertSame('g4_6', $bank->bandForGrade(6));
        $this->assertSame('g7_9', $bank->bandForGrade(7));
        $this->assertSame('g7_9', $bank->bandForGrade(9));
        $this->assertSame('sec', $bank->bandForGrade(10));
        $this->assertSame('sec', $bank->bandForGrade(12));
    }

    public function test_pool_produces_well_formed_self_answered_questions(): void
    {
        $bank = new MathQuestionBank();

        foreach (['g1_3', 'g4_6', 'g7_9', 'sec'] as $band) {
            $questions = $bank->pool($band, 15);

            $this->assertCount(15, $questions, "band {$band} should fill the pool");
            foreach ($questions as $q) {
                $this->assertArrayHasKey('q', $q);
                $this->assertArrayHasKey('options', $q);
                $this->assertArrayHasKey('answer', $q);
                $this->assertNotEmpty($q['q']);
                $this->assertGreaterThanOrEqual(2, count($q['options']));
                $this->assertContains($q['answer'], $q['options'], 'answer must be one of the options');
                $this->assertSame(count($q['options']), count(array_unique($q['options'])), 'options must be unique');
            }
        }
    }

    public function test_generated_arithmetic_answers_are_actually_correct(): void
    {
        $bank = new MathQuestionBank();
        $questions = $bank->pool('g4_6', 60);

        $evaluated = 0;
        foreach ($questions as $q) {
            $question = $this->toLatin($q['q']);
            if (! preg_match('/^(\d+) ([+−×÷]) (\d+)$/u', $question, $m)) {
                continue;
            }
            [$a, $op, $b] = [(int) $m[1], $m[2], (int) $m[3]];
            $expected = match ($op) {
                '+' => $a + $b,
                '−' => $a - $b,
                '×' => $a * $b,
                '÷' => $a / $b,
            };
            $this->assertSame((string) $expected, $this->toLatin($q['answer']), "wrong answer for {$q['q']}");
            $evaluated++;
        }

        $this->assertGreaterThan(0, $evaluated, 'pool should include arithmetic items');
    }

    private function toLatin(string $value): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value,
        );
    }
}
