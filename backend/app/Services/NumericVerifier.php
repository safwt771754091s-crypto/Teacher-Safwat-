<?php

namespace App\Services;

/**
 * Deterministic arithmetic verification, shared by grading and the AI exercise
 * solver. Correctness of numbers must never depend on the language model, so
 * both paths evaluate expressions with the same code here.
 */
class NumericVerifier
{
    /** Convert Arabic-Indic digits (٠-٩) to Latin digits (0-9). */
    public function normalizeDigits(string $value): string
    {
        return str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value,
        );
    }

    /**
     * Evaluate a numeric answer/expression (+ - * / and parentheses). Returns
     * null when the value is not a pure arithmetic expression, so callers never
     * treat free text as a computed result.
     */
    public function evaluate(string $value): ?float
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
            if (is_float($t) || is_int($t)) {
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
}
