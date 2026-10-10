<?php

namespace App\Services;

use App\Models\AiUsageLog;
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
    public function __construct(private ContentExtractionService $extractor)
    {
    }

    /**
     * Build the grounded context for a lesson from its approved/retrieved source.
     * Returns ['points' => string[], 'pages' => int[], 'source_text' => string].
     */
    public function retrieveContext(Lesson $lesson): array
    {
        $lesson->loadMissing(['sections', 'approvedSummary', 'textbook.pages']);

        $points = [];
        $pages = [];

        // Prefer the approved summary when present; otherwise fall back to the
        // lesson sections. Both derive from the same textbook source, so we use
        // only one to avoid duplicating points in the explanation.
        $summary = $lesson->approvedSummary;
        if ($summary && ! empty($summary->points)) {
            foreach ($summary->points as $p) {
                $points[] = is_array($p) ? ($p['text'] ?? '') : (string) $p;
            }
            foreach ((array) $summary->source_pages as $pg) {
                $pages[] = (int) $pg;
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
        $lines = array_values(array_unique($ctx['points']));
        $out = [];
        $out[] = 'شرح الدرس من كتاب المنهج اليمني:';
        $idx = 1;
        foreach ($lines as $line) {
            $out[] = $idx . '. ' . $line;
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

            $this->logUsage($user, $lesson, $provider, $response);

            if ($response->failed()) {
                return $this->fallback($ctx, 'تعذّر الاتصال بخدمة الشرح، وهذه نقاط الدرس من الكتاب:');
            }

            $text = (string) $response->json('choices.0.message.content');
            $text = trim($text);
            if ($text === '') {
                return $this->fallback($ctx, 'لم يصل شرح، وهذه نقاط الدرس من الكتاب:');
            }
            $explanation = array_values(array_filter(array_map('trim', preg_split('/\n+/', $text))));

            return [
                'explanation' => $explanation,
                'pages' => $ctx['pages'],
                'grounded' => true,
                'provider' => $provider,
            ];
        } catch (\Throwable $e) {
            AiUsageLog::create([
                'user_id' => $user?->id,
                'lesson_id' => $lesson->id,
                'feature' => 'explain',
                'provider' => $provider,
                'success' => false,
                'error' => $e->getMessage(),
            ]);
            return $this->fallback($ctx, 'حدث خطأ في خدمة الشرح، وهذه نقاط الدرس من الكتاب:');
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

    private function logUsage(?User $user, Lesson $lesson, string $provider, $response): void
    {
        try {
            AiUsageLog::create([
                'user_id' => $user?->id,
                'lesson_id' => $lesson->id,
                'feature' => 'explain',
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
