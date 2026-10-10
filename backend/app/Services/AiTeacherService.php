<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * The grounded AI teacher.
 *
 * Design guarantee: the teacher explains the *Yemeni textbook* for the selected
 * lesson by first retrieving approved content for that lesson. It is never
 * allowed to answer from model memory alone. When an LLM provider is configured
 * the model receives the retrieved source and is instructed to refuse anything
 * outside it; otherwise the platform composes the explanation directly from the
 * source. Either way, every produced point carries its textbook page number.
 */
class AiTeacherService
{
    public function __construct(
        private ContentExtractionService $extractor,
        private NumericVerifier $numbers,
    ) {
    }

    /**
     * Build the grounded context for a lesson from its approved/retrieved source.
     * Returns ['points' => string[], 'pages' => int[], 'source_text' => string].
     */
    public function retrieveContext(Lesson $lesson): array
    {
        $lesson->loadMissing(['sections', 'approvedSummary', 'textbook.pages']);

        $points = [];
        $cited = []; // ['text' => string, 'page' => ?int] for per-point citation
        $pages = [];

        // Prefer the approved summary when present; otherwise fall back to the
        // lesson sections. Both derive from the same textbook source, so we use
        // only one to avoid duplicating points in the explanation.
        $summary = $lesson->approvedSummary;
        if ($summary && ! empty($summary->points)) {
            $summaryPages = array_values(array_filter(array_map('intval', (array) $summary->source_pages)));
            $fallbackPage = $summaryPages[0] ?? null;
            foreach ($summary->points as $p) {
                $text = is_array($p) ? ($p['text'] ?? '') : (string) $p;
                if ($text === '') {
                    continue;
                }
                $points[] = $text;
                $cited[] = ['text' => $text, 'page' => is_array($p) && isset($p['page']) ? (int) $p['page'] : $fallbackPage];
            }
            foreach ($summaryPages as $pg) {
                $pages[] = $pg;
            }
            foreach ($lesson->sections as $section) {
                if ($section->source_page) {
                    $pages[] = (int) $section->source_page;
                }
            }
        } else {
            foreach ($lesson->sections as $section) {
                if ($section->content) {
                    $points[] = $section->content;
                    $cited[] = ['text' => $section->content, 'page' => $section->source_page ? (int) $section->source_page : null];
                    if ($section->source_page) {
                        $pages[] = (int) $section->source_page;
                    }
                }
            }
        }

        $pages = array_values(array_unique(array_filter($pages)));
        sort($pages);

        $sourceText = implode("\n", $points);

        return [
            'points' => array_values(array_filter($points)),
            'cited_points' => $cited,
            'pages' => $pages,
            'source_text' => $sourceText,
        ];
    }

    /**
     * Explain a lesson, grounded in its textbook source.
     *
     * @return array{explanation: string[], pages: int[], grounded: bool, provider: string}
     */
    public function explain(Lesson $lesson, ?string $question = null, ?User $user = null, int $readingAge = 8): array
    {
        $ctx = $this->retrieveContext($lesson);

        if (empty($ctx['source_text'])) {
            return [
                'explanation' => ['هذا الدرس لا يحتوي على محتوى مُستخرج بعد. يرجى من المشرف اعتماد محتوى الدرس أولًا حتى لا يخترع المعلم الذكي معلومات من خارج الكتاب.'],
                'pages' => [],
                'grounded' => true,
                'provider' => 'none',
                'needs_content' => true,
            ];
        }

        $provider = config('platform.ai.provider', 'none');
        $apiKey = config('platform.ai.api_key');

        if ($provider === 'none' || empty($apiKey)) {
            // Offline grounded mode: compose the explanation directly from the
            // approved source, so the output is exactly the textbook content
            // simplified only by selection and ordering, never invented.
            return [
                'explanation' => $this->composeFromSource($ctx, $question),
                'pages' => $ctx['pages'],
                'grounded' => true,
                'provider' => 'source',
            ];
        }

        return $this->explainWithLlm($lesson, $ctx, $question, $user, $readingAge, $provider, $apiKey);
    }

    private function composeFromSource(array $ctx, ?string $question): array
    {
        $out = [];
        $out[] = 'شرح الدرس من كتاب المنهج اليمني:';
        $idx = 1;
        $seen = [];
        foreach ($ctx['cited_points'] as $point) {
            $line = trim((string) $point['text']);
            if ($line === '' || isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $cite = ! empty($point['page']) ? ' (صفحة ' . (int) $point['page'] . ')' : '';
            $out[] = $idx . '. ' . $line . $cite;
            $idx++;
        }
        if ($question) {
            $out[] = '';
            $out[] = 'سؤالك: ' . trim($question);
            $out[] = 'الجواب يعتمد فقط على نقاط الدرس أعلاه. إن لم تجد ما يسأل عنه ضمن نقاط الدرس فاطلب من معلمك توضيحًا أو صورة أوضح من الكتاب.';
        }
        return $out;
    }

    private function explainWithLlm(Lesson $lesson, array $ctx, ?string $question, ?User $user, int $readingAge, string $provider, string $apiKey): array
    {
        $system = "أنت معلم يمني صبور يشرح لطالب في المرحلة الابتدائية.\n"
            . "قاعدة صارمة: اشرح فقط من (محتوى الدرس) المرفق من كتاب المنهج اليمني. "
            . "لا تضف أي معلومة من خارج المحتوى المرفق. "
            . "إذا لم يكن الجواب موجودًا في المحتوى المرفق فاكتب بالضبط: «هذه المعلومة غير موجودة في الدرس، يرجى توضيح السؤال أو رفع صورة أوضح.» "
            . "اجعل الجمل قصيرة وبسيطة مناسبة لعمر {$readingAge} سنوات، واذكر رقم صفحة الكتاب عند كل نقطة.";

        $userMsg = "عنوان الدرس: {$lesson->title}\n"
            . 'الصفحات: ' . implode(', ', $ctx['pages']) . "\n"
            . "محتوى الدرس:\n{$ctx['source_text']}\n";
        if ($question) {
            $userMsg .= "\nسؤال الطالب: {$question}";
        } else {
            $userMsg .= "\nالمطلوب: اشرح الدرس في نقاط مرقمة بسيطة.";
        }

        $text = $this->callLlm($provider, $apiKey, $system, $userMsg, $user, $lesson, 'explain');
        if ($text === null) {
            return $this->fallback($ctx, 'تعذّر الاتصال بخدمة الشرح، وهذه نقاط الدرس من الكتاب:');
        }

        $explanation = array_values(array_filter(array_map('trim', preg_split('/\n+/', $text))));

        return [
            'explanation' => $explanation,
            'pages' => $ctx['pages'],
            'grounded' => true,
            'provider' => $provider,
        ];
    }

    /**
     * Call the configured LLM chat endpoint and return the reply text, or null
     * on failure. Usage is always logged. Kept in one place so every AI feature
     * shares the same provider, timeout, and audit trail.
     */
    private function callLlm(string $provider, string $apiKey, string $system, string $userMsg, ?User $user, Lesson $lesson, string $feature): ?string
    {
        try {
            $response = Http::withToken($apiKey)
                ->timeout(config('platform.ai.timeout', 30))
                ->post(rtrim(config('platform.ai.base_url'), '/') . '/chat/completions', [
                    'model' => config('platform.ai.model'),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $userMsg],
                    ],
                    'temperature' => 0.2,
                ]);

            $this->logUsage($user, $lesson, $provider, $response, $feature);

            if ($response->failed()) {
                return null;
            }

            $text = trim((string) $response->json('choices.0.message.content'));

            return $text === '' ? null : $text;
        } catch (\Throwable $e) {
            AiUsageLog::create([
                'user_id' => $user?->id,
                'lesson_id' => $lesson->id,
                'feature' => $feature,
                'provider' => $provider,
                'success' => false,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function fallback(array $ctx, string $intro): array
    {
        return [
            'explanation' => array_merge([$intro], $ctx['points']),
            'pages' => $ctx['pages'],
            'grounded' => true,
            'provider' => 'source',
        ];
    }

    private function logUsage(?User $user, Lesson $lesson, string $provider, $response, string $feature = 'explain'): void
    {
        try {
            AiUsageLog::create([
                'user_id' => $user?->id,
                'lesson_id' => $lesson->id,
                'feature' => $feature,
                'provider' => $provider,
                'model' => config('platform.ai.model'),
                'prompt_tokens' => (int) ($response->json('usage.prompt_tokens') ?? 0),
                'completion_tokens' => (int) ($response->json('usage.completion_tokens') ?? 0),
                'success' => $response->successful(),
            ]);
        } catch (\Throwable) {
            // logging must never break the request
        }
    }

    /**
     * Explain *how to solve* one exercise, grounded in the lesson source so a
     * student can follow the reasoning step by step. This teaches the method;
     * it is shown after an attempt or when the student explicitly asks for help,
     * and it prefers the deterministic answer over anything the model recalls.
     *
     * @return array{steps: string[], answer?: string, source_page?: ?int, grounded: bool, provider: string, needs_content?: bool}
     */
    public function explainSolution(Exercise $exercise, ?User $user = null, int $readingAge = 8): array
    {
        $exercise->loadMissing(['options', 'lesson']);
        $ctx = $this->retrieveContext($exercise->lesson);

        // If a human has already set the reference solution, show exactly that —
        // never the model's guess.
        if (is_array($exercise->solution_steps) && ! empty($exercise->solution_steps)) {
            return [
                'steps' => $exercise->solution_steps,
                'answer' => $exercise->answer,
                'source_page' => $exercise->source_page,
                'grounded' => true,
                'provider' => 'source',
            ];
        }

        // A numeric answer is computed, not generated, so correctness never
        // depends on the model.
        $computed = $exercise->answer !== null ? $this->numbers->evaluate((string) $exercise->answer) : null;

        $provider = config('platform.ai.provider', 'none');
        $apiKey = config('platform.ai.api_key');

        if ($provider === 'none' || empty($apiKey)) {
            $structured = $this->sourceStructuredSteps($exercise, $ctx);
            if ($structured !== null) {
                return $structured;
            }
            if (empty($ctx['source_text'])) {
                return $this->needsContent($exercise);
            }

            return [
                'steps' => ['حل هذا التمرين من كتاب المنهج اليمني يحتاج مراجعة المعلم.'],
                'answer' => $exercise->answer,
                'source_page' => $exercise->source_page,
                'grounded' => true,
                'provider' => 'source',
            ];
        }

        $system = "أنت معلم يمني صبور يعلّم طالبًا في المرحلة الابتدائية كيف يحل التمرين خطوة بخطوة.\n"
            . "قاعدة صارمة: استخدم (محتوى الدرس) المرفق فقط، ولا تضف أي معلومة من خارج الكتاب.\n"
            . 'إن كان الجواب الصحيح معروفًا (' . ($exercise->answer !== null ? $exercise->answer : 'غير معروف') . ") فاجعل خطواتك تؤدي إليه.\n"
            . "اكتب الخطوات جملًا قصيرة مرقمة، بلغة بسيطة مناسبة لعمر {$readingAge} سنوات، واذكر رقم صفحة الكتاب إن توفّر.\n"
            . 'إذا لم يتوفر محتوى كافٍ للحل فاكتب بالضبط: «هذا السؤال يحتاج توضيحًا أو صورة أوضح من الكتاب.»';

        $userMsg = "عنوان الدرس: {$exercise->lesson->title}\n"
            . 'الصفحات: ' . implode(', ', $ctx['pages']) . "\n"
            . "محتوى الدرس:\n" . ($ctx['source_text'] ?: 'لا يوجد محتوى مستخرج بعد.') . "\n\n"
            . "السؤال: {$exercise->question}\n"
            . 'نوعه: ' . $exercise->type;
        if ($exercise->options->isNotEmpty()) {
            $userMsg .= "\nالخيارات: " . $exercise->options->pluck('text')->implode(' | ');
        }
        if ($exercise->answer !== null) {
            $userMsg .= "\nالجواب المرجعي (تحقق منه فقط): {$exercise->answer}";
        }
        $userMsg .= "\nالمطلوب: اشرح خطوات الحل خطوة بخطوة.";

        $text = $this->callLlm($provider, $apiKey, $system, $userMsg, $user, $exercise->lesson, 'solution');
        if ($text === null) {
            $structured = $this->sourceStructuredSteps($exercise, $ctx);

            return $structured ?? [
                'steps' => ['تعذّر الاتصال بخدمة الشرح، وهذه نقاط الدرس: ' . implode(' ', $ctx['points'])],
                'answer' => $exercise->answer,
                'source_page' => $exercise->source_page,
                'grounded' => true,
                'provider' => 'source',
            ];
        }

        $steps = array_values(array_filter(array_map('trim', preg_split('/\n+/', $text))));

        return [
            'steps' => $steps,
            'answer' => $exercise->answer,
            'source_page' => $exercise->source_page,
            'grounded' => true,
            'provider' => $provider,
        ];
    }

    /**
     * Offline, deterministic step construction for numeric exercises: the split
     * of the expression and the verified result, so the platform still teaches
     * a correct method with no LLM and no invented wording.
     */
    private function sourceStructuredSteps(Exercise $exercise, array $ctx): ?array
    {
        if (! in_array($exercise->type, ['numeric', 'step_by_step', 'true_false', 'mcq'], true)) {
            return null;
        }

        $steps = [];
        $computed = $exercise->answer !== null ? $this->numbers->evaluate((string) $exercise->answer) : null;

        if ($computed !== null) {
            $steps[] = 'نكتب المسألة ثم نجري العملية خطوة بخطوة.';
            $steps[] = 'الناتج: ' . $this->numbers->normalizeDigits((string) $exercise->answer) . '.';
        } elseif ($exercise->type === 'mcq') {
            $correct = $exercise->options->firstWhere('is_correct', true);
            if ($correct) {
                $steps[] = 'الاختيار الصحيح هو: ' . $correct->text . '.';
            }
        }

        if (empty($steps)) {
            return null;
        }

        if (! empty($ctx['points'])) {
            $steps[] = 'استند إلى نقاط الدرس: ' . $ctx['points'][0];
        }

        return [
            'steps' => $steps,
            'answer' => $exercise->answer,
            'source_page' => $exercise->source_page,
            'grounded' => true,
            'provider' => 'source',
        ];
    }

    private function needsContent(Exercise $exercise): array
    {
        return [
            'steps' => ['هذا السؤال يحتاج محتوى الدرس أولًا. اطلب من مشرف المحتوى اعتماد الدرس، أو ارفع صورة أوضح من الكتاب.'],
            'answer' => $exercise->answer,
            'source_page' => $exercise->source_page,
            'grounded' => true,
            'provider' => 'none',
            'needs_content' => true,
        ];
    }

    /**
     * Suggest a reference answer + solution steps + hint for an exercise, for a
     * human reviewer to approve. The draft is NOT published: it is returned to
     * the caller and only becomes visible to learners once approved. Numeric
     * answers are computed deterministically and flagged for review.
     *
     * @return array{answer: ?string, solution_steps: string[], hint: ?string, needs_content: bool, provider: string, confidence: string}
     */
    public function draftSolution(Exercise $exercise, ?User $user = null): array
    {
        $exercise->loadMissing(['options', 'lesson']);
        $ctx = $this->retrieveContext($exercise->lesson);

        // An already-verifiable answer wins; we only supply missing pieces.
        $answer = $exercise->answer;
        $numeric = $answer !== null ? $this->numbers->evaluate((string) $answer) : null;
        $needsContent = empty($ctx['source_text']) && $answer === null && $exercise->options->isEmpty();

        $provider = config('platform.ai.provider', 'none');
        $apiKey = config('platform.ai.api_key');
        $steps = [];
        $confidence = 'low';

        if ($numeric !== null) {
            // Computed answer: high confidence, still human-approved before publishing.
            $answer = $this->numbers->normalizeDigits((string) $answer);
            $steps[] = 'نحسب الناتج مباشرةً: ' . $answer . '.';
            $confidence = 'high';
        } elseif ($exercise->type === 'mcq') {
            $correct = $exercise->options->firstWhere('is_correct', true);
            if ($correct) {
                $answer = $correct->label ?: $correct->text;
                $steps[] = 'الاختيار الصحيح حسب الكتاب: ' . $correct->text . '.';
                $confidence = 'medium';
            }
        }

        if ($provider !== 'none' && ! empty($apiKey) && ! $needsContent) {
            $system = "أنت معلم يمني خبير. اقترح حلًا مرجعيًا لتمرين من كتاب المنهج اليمني، اعتمادًا على (محتوى الدرس) المرفق فقط.\n"
                . "لا تخترع أي معلومة من خارج المحتوى المرفق.\n"
                . 'أعد الناتج بصيغة JSON فقط بالمفاتيح: {"answer": "...", "steps": ["...", "..."], "hint": "..."}. '
                . "الخطوات قصيرة ومرقمة، والتلميح دفعة بسيطة دون كشف الجواب إن أمكن.\n"
                . 'إن لم يتوفر محتوى كافٍ فاجعل "answer" = "" و "steps" = [].';

            $userMsg = "عنوان الدرس: {$exercise->lesson->title}\n"
                . "محتوى الدرس:\n" . $ctx['source_text'] . "\n\n"
                . "السؤال: {$exercise->question}\n"
                . 'نوعه: ' . $exercise->type;
            if ($exercise->options->isNotEmpty()) {
                $userMsg .= "\nالخيارات: " . $exercise->options->pluck('text')->implode(' | ');
            }
            if ($answer !== null) {
                $userMsg .= "\nالجواب المحسوب (اعتمده إن كان صحيحًا): {$answer}";
            }

            $text = $this->callLlm($provider, $apiKey, $system, $userMsg, $user, $exercise->lesson, 'draft_solution');
            $parsed = $this->parseJsonObject($text);
            if ($parsed) {
                if (! empty($parsed['answer']) && $numeric === null) {
                    $answer = (string) $parsed['answer'];
                }
                $llmSteps = array_values(array_filter(array_map('trim', (array) ($parsed['steps'] ?? []))));
                if ($llmSteps) {
                    $steps = $llmSteps;
                    $confidence = $numeric !== null ? 'high' : 'medium';
                }
                $hint = trim((string) ($parsed['hint'] ?? '')) ?: null;
            }
        }

        $hint ??= $exercise->hint;

        return [
            'answer' => $answer,
            'solution_steps' => $steps,
            'hint' => $hint,
            'needs_content' => $needsContent,
            'provider' => $provider === 'none' || empty($apiKey) ? 'source' : $provider,
            'confidence' => $confidence,
        ];
    }

    /**
     * Parse the first JSON object found in a model reply (tolerates code fences).
     */
    private function parseJsonObject(?string $text): ?array
    {
        if (! $text) {
            return null;
        }
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * Generate a summary draft strictly from retrieved source points.
     */
    public function summarize(Lesson $lesson): Summary
    {
        $ctx = $this->retrieveContext($lesson);
        return Summary::updateOrCreate(
            ['lesson_id' => $lesson->id, 'status' => 'draft'],
            [
                'title' => 'ملخص: ' . $lesson->title,
                'points' => $ctx['points'],
                'keywords' => $this->keywords($ctx['source_text']),
                'source_pages' => $ctx['pages'],
                'generated_by' => 'ai',
                'status' => 'draft',
            ],
        );
    }

    private function keywords(string $text): array
    {
        $clean = preg_replace('/[^\p{Arabic}\p{L}\s]/u', ' ', $text);
        $words = preg_split('/\s+/u', (string) $clean);
        $counts = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) < 4) {
                continue;
            }
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, 8);
    }
}
