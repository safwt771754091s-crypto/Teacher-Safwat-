<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonSection;

/**
 * Turns a lesson's *real* textbook text into an interactive model spec.
 *
 * The rule that shapes this whole class: a model is only produced when the
 * lesson text actually contains the thing the model shows. An angle model needs
 * a degree figure in the text; a fraction model needs a fraction. Nothing is
 * invented, and the sentence the model was built from is kept as the model's
 * `prompt`, so the learner always sees the textbook line it illustrates.
 *
 * The spec is the same JSON the client engine already understands
 * (web/assets/interactive*.js), so no backend rendering is involved and new
 * kinds ship by adding a rule here plus a KINDS entry there.
 */
class InteractiveModelService
{
    /** A short model is only worth showing when it has real content to show. */
    /**
     * Build models for every section of a lesson that carries a grounded signal.
     * Idempotent: a section that already has a model is left alone, so re-running
     * generation never duplicates a figure.
     *
     * @return int number of models created
     */
    public function generateForLesson(Lesson $lesson): int
    {
        $lesson->loadMissing('sections');
        $created = 0;

        foreach ($lesson->sections as $section) {
            if (! empty($section->visual['kind'])) {
                continue; // already has a model; do not duplicate
            }

            $spec = $this->specForSection($section, $lesson);
            if ($spec === null) {
                continue;
            }

            $section->update(['visual' => $spec]);
            $created++;
        }

        return $created;
    }

    /**
     * Choose and fill one model for a section, or null when the text offers no
     * grounded signal. Order matters: the most specific reading of the text wins.
     *
     * @return array{kind:string,params:array<string,mixed>,prompt:string,source_page:?int}|null
     */
    public function specForSection(LessonSection $section, ?Lesson $lesson = null): ?array
    {
        $text = trim((string) $section->content);
        if ($text === '') {
            return null;
        }

        // Read the subject through the textbook relation: Lesson::subject() is a
        // convenience accessor, not an Eloquent relation, so it cannot be used
        // as a lazy-loaded attribute here.
        $subject = $lesson?->textbook?->subject?->name ?? '';
        $page = $section->source_page;

        foreach ($this->rules() as $rule) {
            // A subject-scoped rule only applies to that subject's books.
            if (! empty($rule['subjects']) && ! $this->subjectMatches($subject, $rule['subjects'])) {
                continue;
            }

            $params = ($rule['params'])($text);
            if ($params === null) {
                continue;
            }

            return [
                'kind' => $rule['kind'],
                'params' => $params,
                'prompt' => $this->promptFrom($text),
                'source_page' => $page,
            ];
        }

        return null;
    }

    /**
     * The model rules, most specific first. Each `params` closure returns a
     * filled params array when the text really contains the signal, or null.
     *
     * @return list<array{kind:string,subjects?:list<string>,params:callable}>
     */
    private function rules(): array
    {
        return [
            // A fraction written as "٣/٤" or "3/4" in the text.
            ['kind' => 'fractions', 'subjects' => ['الرياضيات'], 'params' => fn (string $t) => $this->fractionParams($t)],

            // A degrees figure ("٦٠°" / "60 درجة") on an angles lesson.
            ['kind' => 'angles', 'subjects' => ['الرياضيات'], 'params' => fn (string $t) => $this->angleParams($t)],

            // A power expression such as "ص = س٢" / "f(x)=x^2" / "y=x^2".
            ['kind' => 'function-graph', 'subjects' => ['الرياضيات'], 'params' => fn (string $t) => $this->functionParams($t)],

            // A statistics table / list of numbers becomes a bar chart.
            ['kind' => 'bar-chart', 'params' => fn (string $t) => $this->barChartParams($t)],

            // Numbers on a number line ("قارن بين -٢ و ٣").
            ['kind' => 'number-line', 'subjects' => ['الرياضيات'], 'params' => fn (string $t) => $this->numberLineParams($t)],

            // A triangle with two given sides.
            ['kind' => 'triangle', 'subjects' => ['الرياضيات'], 'params' => fn (string $t) => $this->triangleParams($t)],

            // A science experiment / circuit mention with a voltage.
            ['kind' => 'circuit', 'subjects' => ['العلوم', 'الفيزياء'], 'params' => fn (string $t) => $this->circuitParams($t)],

            // States of matter.
            ['kind' => 'states-of-matter', 'subjects' => ['العلوم', 'الفيزياء', 'الكيمياء'], 'params' => fn (string $t) => $this->statesParams($t)],

            // A wave with a stated wavelength.
            ['kind' => 'wave', 'subjects' => ['العلوم', 'الفيزياء'], 'params' => fn (string $t) => $this->waveParams($t)],

            // Counting / numbers for early grades.
            ['kind' => 'counting', 'params' => fn (string $t) => $this->countingParams($t)],

            // An English sentence to rebuild.
            ['kind' => 'sentence-builder', 'subjects' => ['اللغة الإنجليزية'], 'params' => fn (string $t) => $this->sentenceParams($t)],
        ];
    }

    // ---------------------------------------------------------------------
    // Signal readers. Each returns params only when the text really has it.
    // ---------------------------------------------------------------------

    private function fractionParams(string $t): ?array
    {
        if (! preg_match('#([٠-٩0-9]+)\s*/\s*([٠-٩0-9]+)#u', $t, $m)) {
            return null;
        }
        $num = $this->int($m[1]);
        $den = $this->int($m[2]);
        if ($num <= 0 || $den <= 1 || $num > 20 || $den > 20) {
            return null;
        }

        return ['num' => $num, 'den' => $den];
    }

    private function angleParams(string $t): ?array
    {
        if (! preg_match('/([٠-٩0-9]{1,3})\s*(?:°|درجة|درجه)/u', $t, $m)) {
            return null;
        }
        $deg = $this->int($m[1]);
        if ($deg < 1 || $deg > 360) {
            return null;
        }

        return ['deg' => $deg];
    }

    private function functionParams(string $t): ?array
    {
        // x^2 / س^٢ / س٢ with an explicit "=" so it is really a function rule.
        if (preg_match('/=\s*([a-z\x{0621}-\x{064A}])\s*\^\s*([\x{0662}2])/u', $t, $m)) {
            return ['fn' => $m[1].'^2', 'at' => 1];
        }

        return null;
    }

    private function barChartParams(string $t): ?array
    {
        // A run of at least three numbers (a table row or a data list) becomes a
        // bar chart. The labels are the lesson's own numbers, never invented.
        if (! preg_match_all('/([٠-٩0-9]+)/u', $t, $m)) {
            return null;
        }
        $nums = array_map(fn ($n) => $this->int($n), $m[1]);
        $nums = array_values(array_filter($nums, fn ($n) => $n > 0 && $n <= 1000));
        if (count($nums) < 3) {
            return null;
        }
        $nums = array_slice($nums, 0, 8);

        return [
            'data' => $nums,
            'labels' => array_map(fn ($i) => (string) ($i + 1), array_keys($nums)),
        ];
    }

    private function numberLineParams(string $t): ?array
    {
        if (! preg_match('/(?:قارن|رتب|عدد|الأعداد|الاعداد)/u', $t)) {
            return null;
        }
        if (! preg_match_all('/(-?[٠-٩0-9]+)/u', $t, $m)) {
            return null;
        }
        $nums = array_map(fn ($n) => $this->int($n), $m[1]);
        $nums = array_values(array_filter($nums, fn ($n) => abs($n) <= 100));
        if (count($nums) < 2) {
            return null;
        }
        $a = $nums[0];
        $b = $nums[1];
        if ($a === $b) {
            return null;
        }

        return ['a' => $a, 'b' => $b, 'lo' => min($a, $b) - 2, 'hi' => max($a, $b) + 2];
    }

    private function triangleParams(string $t): ?array
    {
        if (! preg_match('/(?:مثلث|المثلث)/u', $t)) {
            return null;
        }
        if (! preg_match_all('/([٠-٩0-9]+)\s*(?:سم|م)/u', $t, $m)) {
            return null;
        }
        $sides = array_map(fn ($n) => $this->int($n), $m[1]);
        $sides = array_values(array_filter($sides, fn ($n) => $n > 0 && $n <= 100));
        if (count($sides) < 2) {
            return null;
        }

        return ['a' => $sides[0], 'b' => $sides[1]];
    }

    private function circuitParams(string $t): ?array
    {
        if (! preg_match('/(?:دائرة|الدارة|مقاومة|المقاومة|بطارية)/u', $t)) {
            return null;
        }
        $v = preg_match('/([٠-٩0-9]+)\s*(?:فولت|ڤولت|v\b)/iu', $t, $m) ? $this->int($m[1]) : null;
        if ($v === null || $v <= 0 || $v > 240) {
            return null;
        }

        return ['mode' => 'series', 'v' => $v, 'r1' => 2, 'r2' => 3];
    }

    private function statesParams(string $t): ?array
    {
        if (! preg_match('/(?:صلب|سائل|غاز|انصهار|تبخر|التجمد)/u', $t)) {
            return null;
        }
        // A stated temperature drives the model; without one it still shows the
        // three states at a neutral temperature, which is honest.
        $temp = preg_match('/(-?[٠-٩0-9]+)\s*(?:°|درجة|درجه)/u', $t, $m) ? $this->int($m[1]) : 25;

        return ['t' => max(-50, min(150, $temp))];
    }

    private function waveParams(string $t): ?array
    {
        if (! preg_match('/(?:موجة|الموجات|الطول الموجي|تردد)/u', $t)) {
            return null;
        }
        $lambda = preg_match('/([٠-٩0-9]+)\s*(?:سم|متر|م\b)/u', $t, $m) ? $this->int($m[1]) : null;
        if ($lambda === null || $lambda <= 0) {
            return null;
        }

        return ['amp' => 1, 'lambda' => $lambda, 'speed' => 1];
    }

    private function countingParams(string $t): ?array
    {
        if (! preg_match('/(?:عد|عدد|نعد|الأعداد|الاعداد)/u', $t)) {
            return null;
        }
        if (! preg_match('/([٠-٩0-9]+)/u', $t, $m)) {
            return null;
        }
        $n = $this->int($m[1]);
        if ($n < 1 || $n > 20) {
            return null;
        }

        // The engine's param is `n`; passing `count` would silently fall back to 5.
        return ['n' => $n];
    }

    private function sentenceParams(string $t): ?array
    {
        // Only a genuine English sentence (several Latin words) is rebuildable.
        if (! preg_match_all('/[A-Za-z]{2,}/', $t, $m)) {
            return null;
        }
        $words = $m[0];
        if (count($words) < 3) {
            return null;
        }

        // The engine shuffles `words` and checks against `answer`; it needs the
        // two separately, not one pre-joined sentence.
        return ['words' => array_values($words), 'answer' => implode(' ', $words)];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** The sentence a model was built from, kept verbatim as its prompt. */
    private function promptFrom(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_substr($text, 0, 160);
    }

    private function subjectMatches(string $subject, array $allowed): bool
    {
        foreach ($allowed as $a) {
            if ($a !== '' && mb_strpos($subject, $a) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Read a run of Arabic-Indic or Latin digits as an int. */
    private function int(string $value): int
    {
        $latin = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return (int) $latin;
    }
}
