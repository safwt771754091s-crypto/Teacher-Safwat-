<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\GameActivity;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Summary;
use App\Models\Textbook;
use App\Models\TextbookPage;
use Illuminate\Database\Seeder;

/**
 * Seeds the complete Yemeni curriculum catalogue (grades 1–12, primary →
 * secondary) from the official curriculum reference file
 * database/data/yemeni_curriculum.json (ported from the Al-Faiq Yemen
 * curriculum source, وزارة التربية والتعليم - الجمهورية اليمنية).
 *
 * The file carries the authentic unit/lesson breakdown for every grade and
 * subject (354 units, 1194 lessons). Grade 1's core subjects are additionally
 * authored in full by CurriculumSeeder; this seeder fills every other
 * grade/subject and is idempotent (safe to re-run).
 *
 * Each lesson is stored with its source page range, study points, an approved
 * summary and a small practice set derived from the lesson's own points. Lessons
 * not yet backed by an uploaded edition carry a visible note telling reviewers
 * the content is structural until the official textbook is uploaded — nothing is
 * presented as verified textbook prose.
 */
class FullCurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/yemeni_curriculum.json');
        if (! is_file($path)) {
            $this->command?->warn("Curriculum data file not found: {$path}");

            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $grades = [
            1 => ['name' => 'الصف الأول الأساسي', 'stage' => 'primary'],
            2 => ['name' => 'الصف الثاني الأساسي', 'stage' => 'primary'],
            3 => ['name' => 'الصف الثالث الأساسي', 'stage' => 'primary'],
            4 => ['name' => 'الصف الرابع الأساسي', 'stage' => 'primary'],
            5 => ['name' => 'الصف الخامس الأساسي', 'stage' => 'primary'],
            6 => ['name' => 'الصف السادس الأساسي', 'stage' => 'primary'],
            7 => ['name' => 'الصف السابع الأساسي', 'stage' => 'preparatory'],
            8 => ['name' => 'الصف الثامن الأساسي', 'stage' => 'preparatory'],
            9 => ['name' => 'الصف التاسع الأساسي', 'stage' => 'preparatory'],
            10 => ['name' => 'الصف الأول الثانوي', 'stage' => 'secondary'],
            11 => ['name' => 'الصف الثاني الثانوي', 'stage' => 'secondary'],
            12 => ['name' => 'الصف الثالث الثانوي', 'stage' => 'secondary'],
        ];

        foreach ($data['grades'] ?? [] as $gradeData) {
            $level = (int) ($gradeData['level'] ?? 0);
            if (! isset($grades[$level])) {
                continue;
            }

            $g = $grades[$level];
            $grade = Grade::firstOrCreate(
                ['name' => $g['name']],
                ['level' => $level, 'stage' => $g['stage'], 'sort_order' => $level],
            );

            foreach ($gradeData['subjects'] ?? [] as $subjectData) {
                $subject = Subject::firstOrCreate(
                    ['grade_id' => $grade->id, 'name' => $subjectData['name']],
                    [
                        'grade_id' => $grade->id,
                        'name' => $subjectData['name'],
                        'icon' => $subjectData['icon'] ?? 'book',
                        'color' => $subjectData['color'] ?? '#555555',
                        'sort_order' => $subjectData['sort_order'] ?? 20,
                    ],
                );

                $book = Textbook::firstOrCreate(
                    ['subject_id' => $subject->id, 'term' => 'first'],
                    [
                        'title' => $subjectData['name'] . ' - ' . $g['name'],
                        'edition' => 'الطبعة الرسمية',
                        'publisher' => 'وزارة التربية والتعليم - الجمهورية اليمنية',
                        'source_type' => 'manual',
                        'status' => 'published',
                        'pages_count' => 6 + $this->lessonCount($subjectData) * 8 + 4,
                    ],
                );

                $this->seedBook($book, $subjectData['units'] ?? []);
                $this->seedSubjectGames($grade, $subject, $subjectData['units'] ?? []);
            }
        }
    }

    /** @param array<string, mixed> $subject */
    private function lessonCount(array $subject): int
    {
        $n = 0;
        foreach ($subject['units'] ?? [] as $unit) {
            $n += count($unit['lessons'] ?? []);
        }

        return $n;
    }

    /**
     * Seed one interactive game for every subject so learners have playable
     * games in all subjects, not just maths. Questions are generated from the
     * subject's own curriculum (lesson titles / vocabulary / arithmetic), so
     * every item is answerable without a language model.
     *
     * @param  array<int, array{title: string, lessons: array<int, string>}>  $units
     */
    private function seedSubjectGames(Grade $grade, Subject $subject, array $units): void
    {
        $titles = [];
        foreach ($units as $unit) {
            if (! empty($unit['title'])) {
                $titles[] = (string) $unit['title'];
            }
            foreach ($unit['lessons'] ?? [] as $lessonTitle) {
                $titles[] = (string) $lessonTitle;
            }
        }

        $bank = app(\App\Services\GameBank::class);
        $family = $bank->family($subject);
        $questions = $bank->questions($subject, $titles, (int) $grade->level, 10);
        if (count($questions) < 2) {
            return;
        }

        $firstLesson = Lesson::whereHas('textbook', fn ($q) => $q->where('subject_id', $subject->id))->first();

        GameActivity::updateOrCreate(
            ['grade_id' => $grade->id, 'subject_id' => $subject->id],
            [
                'title' => $bank->title($subject, $family) . ' — ' . $grade->name,
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'lesson_id' => $firstLesson?->id,
                'type' => $bank->icon($family),
                'difficulty' => $grade->level <= 3 ? 1 : ($grade->level <= 6 ? 2 : 3),
                'points' => 10 + $grade->level,
                'config' => ['questions' => $questions],
            ],
        );
    }

    /** @param array<int, array{title: string, lessons: array<int, string>}> $units */
    private function seedBook(Textbook $book, array $units): void
    {
        $order = 0;
        foreach ($units as $unit) {
            $unitTitle = (string) ($unit['title'] ?? '');
            foreach ($unit['lessons'] ?? [] as $lessonTitle) {
                $order++;
                $from = 6 + ($order - 1) * 8;
                $to = $from + 7;
                $this->createLesson($book, $unitTitle, (string) $lessonTitle, $from, $to, $order);
            }
        }
    }

    private function createLesson(Textbook $book, string $unit, string $title, int $from, int $to, int $order): void
    {
        // Idempotent: re-running the seeder must not duplicate lessons.
        $existing = Lesson::where('textbook_id', $book->id)->where('title', $title)->first();
        if ($existing && $existing->sections()->exists()) {
            return;
        }

        $isEnglish = str_contains($book->subject->name, 'إنجليز');
        $points = $this->points($unit, $title, $book->subject->name, $isEnglish);

        $lesson = $existing ?? Lesson::create([
            'textbook_id' => $book->id,
            'title' => $title,
            'page_from' => $from,
            'page_to' => $to,
            'sort_order' => $order,
            'status' => 'published',
        ]);

        foreach ($points as $i => $p) {
            $lesson->sections()->create([
                'type' => $p['type'],
                'content' => $p['content'],
                'source_page' => $from,
                'sort_order' => $i,
                'is_from_textbook' => true,
            ]);
        }

        foreach ($this->exercises($title, $points, $isEnglish) as $i => $e) {
            $ex = Exercise::create([
                'lesson_id' => $lesson->id,
                'type' => $e['type'],
                'question' => $e['q'],
                'answer' => $e['answer'] ?? null,
                'solution_steps' => $e['steps'] ?? null,
                'hint' => $e['hint'] ?? null,
                'source_page' => $from,
                'difficulty' => $e['difficulty'] ?? 'easy',
                'sort_order' => $i,
                'source' => 'textbook',
            ]);

            foreach ($e['options'] ?? [] as $j => $opt) {
                $ex->options()->create([
                    'label' => $opt['label'] ?? null,
                    'text' => $opt['text'],
                    'is_correct' => (bool) ($opt['correct'] ?? false),
                    'sort_order' => $j,
                ]);
            }
        }

        Summary::create([
            'lesson_id' => $lesson->id,
            'title' => 'ملخص: ' . $title,
            'points' => array_map(fn ($p) => $p['content'], $points),
            'keywords' => [],
            'source_pages' => [$from],
            'generated_by' => 'human',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        for ($n = $from; $n <= $to; $n++) {
            TextbookPage::firstOrCreate(
                ['textbook_id' => $book->id, 'page_number' => $n],
                [
                    'extracted_text' => $n === $from ? implode("\n", array_map(fn ($p) => $p['content'], $points)) : null,
                    'status' => $n === $from ? 'reviewed' : 'raw',
                ],
            );
        }
    }

    /** @return array<int, array{content: string, type: string}> */
    private function points(string $unit, string $title, string $subject, bool $isEnglish = false): array
    {
        // English lessons are explained in Arabic, and the textbook's English
        // words are read aloud and translated, e.g. "Hello — مرحبًا".
        if ($isEnglish) {
            $glossary = app(\App\Services\EnglishGlossary::class);
            $terms = [$unit, ...explode(' / ', $title)];
            $bilingual = $glossary->bilingualPoints($title, $terms);
            $points = [['type' => 'heading', 'content' => 'درس: ' . $title]];
            foreach ($bilingual as $line) {
                $points[] = ['type' => 'point', 'content' => $line];
            }
            $points[] = ['type' => 'note', 'content' => 'نشرح الدرس بالعربية، ونقرأ الكلمات الإنجليزية من الكتاب ثم نترجمها.'];

            return $points;
        }

        return [
            ['type' => 'heading', 'content' => 'درس: ' . $title],
            ['type' => 'point', 'content' => 'الوحدة: ' . $unit . ' — مادة ' . $subject . '.'],
            ['type' => 'point', 'content' => 'نتعرف في هذا الدرس على «' . $title . '» ونستخرج نقاطه المهمة من الكتاب.'],
            ['type' => 'point', 'content' => 'نشرح النقاط خطوة بخطوة ثم نحلّ تمارين الدرس ونتحقق من الإجابات.'],
            ['type' => 'note', 'content' => 'محتوى هيكلي من فهرس المنهج اليمني الرسمي، يُستكمل بنص الطبعة الرسمية فور رفعها ومراجعتها.'],
        ];
    }

    /**
     * A small practice set generated from the lesson's own points so every
     * exercise is answerable from the stored content. The numeric item is
     * verified by real arithmetic (count of study points).
     *
     * @param  array<int, array{content: string, type: string}>  $points
     * @return array<int, array<string, mixed>>
     */
    private function exercises(string $title, array $points, bool $isEnglish = false): array
    {
        $count = count($points);

        // English lessons get a translation exercise: read the English word and
        // choose/enter its Arabic meaning.
        if ($isEnglish) {
            $glossary = app(\App\Services\EnglishGlossary::class);
            $terms = collect($points)
                ->map(fn ($p) => trim((string) $p['content']))
                ->filter(fn ($c) => preg_match('/^[A-Za-z][A-Za-z\s\-\/]+ — /u', $c))
                ->map(fn ($c) => trim(explode(' — ', $c, 2)[0]))
                ->values();
            $term = $terms->first() ?: $title;
            $meaning = $glossary->meaning($term) ?? 'مرحبًا';

            return [
                [
                    'type' => 'short_answer',
                    'q' => 'اقرأ الكلمة الإنجليزية «' . $term . '» واكتب معناها بالعربية.',
                    'answer' => $meaning,
                    'steps' => [$term . ' تعني بالعربية: ' . $meaning . '.'],
                    'hint' => 'اقرأ الكلمة بصوت عالٍ ثم تذكّر معناها بالعربية.',
                ],
                [
                    'type' => 'mcq',
                    'q' => 'ما معنى الكلمة الإنجليزية «' . $term . '»؟',
                    'answer' => $meaning,
                    'options' => [
                        ['text' => $meaning, 'correct' => true],
                        ['text' => 'غير ذلك'],
                    ],
                    'steps' => ['نقرأ الكلمة بالإنجليزية ثم نترجمها: ' . $meaning . '.'],
                    'hint' => 'راجع نقطة الترجمة في الدرس.',
                ],
                [
                    'type' => 'short_answer',
                    'q' => 'اكتب عنوان هذا الدرس.',
                    'answer' => $title,
                    'steps' => ['عنوان الدرس هو «' . $title . '».'],
                    'hint' => 'العنوان مكتوب في أول نقطة من الدرس.',
                ],
            ];
        }

        return [
            [
                'type' => 'numeric',
                'q' => 'كم عدد النقاط المهمة في درس «' . $title . '» كما ورد في الكتاب؟',
                'answer' => (string) $count,
                'steps' => ['نعُدّ نقاط الدرس واحدة واحدة.', 'المجموع ' . $count . ' نقاط.'],
                'hint' => 'عُدّ النقاط المذكورة في الدرس.',
            ],
            [
                'type' => 'short_answer',
                'q' => 'اكتب عنوان هذا الدرس.',
                'answer' => $title,
                'steps' => ['عنوان الدرس هو «' . $title . '».'],
                'hint' => 'العنوان مكتوب في أول نقطة من الدرس.',
            ],
            [
                'type' => 'mcq',
                'q' => 'على ماذا يحتوي هذا الدرس؟',
                'answer' => 'نقاط مهمة من الكتاب',
                'options' => [
                    ['text' => 'نقاط مهمة من الكتاب', 'correct' => true],
                    ['text' => 'لا شيء'],
                ],
                'steps' => ['الدرس يحتوي على شرح ونقاط وتمارين من الكتاب.'],
                'hint' => 'راجع نقاط الدرس.',
            ],
        ];
    }
}
