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
 * Authored content for الرياضيات — الصف الثالث الثانوي (القسم العلمي).
 *
 * This is the first fully authored secondary book. Unlike the structural
 * catalogue seeded by FullCurriculumSeeder (unit/lesson titles only, with a
 * visible "structural content" note), every lesson here carries real sections,
 * a summary, and a graded exercise set with worked solution steps — all
 * traceable to the official textbook (كتاب الرياضيات للصف الثالث الثانوي —
 * القسم العلمي، وزارة التربية والتعليم، الطبعة 2017).
 *
 * The seven units mirror the book's own table of contents:
 *   1. الأعداد المركبة           2. مبدأ العدّ ومبرهنة ذات الحدين
 *   3. الاحتمالات                 4. القطوع المخروطية
 *   5. الهندسة الفضائية           6. التفاضل
 *   7. التكامل
 *
 * Non-destructive: the structural placeholder lessons the catalogue seeder
 * created for this subject are archived (status = draft) rather than deleted,
 * so nothing is lost and learners only see the authored content.
 */
class Grade12MathSeeder extends Seeder
{
    /** Official scientific-branch textbook (the first real book on the platform). */
    private const SOURCE_URL = 'https://s1.yemen-books.com/files/books/12th/كتاب_الرياضيات_القسم_العلمي_الطبعة_2017_للصف_الثالث_ثانوي_اليمن.pdf';

    public function run(): void
    {
        $grade = Grade::where('level', 12)->first();
        if (! $grade) {
            return;
        }

        $subject = Subject::firstOrCreate(
            ['grade_id' => $grade->id, 'name' => 'الرياضيات'],
            ['grade_id' => $grade->id, 'name' => 'الرياضيات', 'icon' => 'calc', 'color' => '#2b5f8a', 'sort_order' => 4],
        );

        $book = Textbook::firstOrCreate(
            ['subject_id' => $subject->id, 'term' => 'first'],
            [
                'title' => 'الرياضيات (القسم العلمي) — الصف الثالث الثانوي',
                'edition' => 'الطبعة 2017',
                'publisher' => 'وزارة التربية والتعليم - الجمهورية اليمنية',
                'source_type' => 'pdf',
                'status' => 'published',
            ],
        );

        // Mark the book as the scientific branch and point it at the official PDF.
        $book->fill([
            'title' => 'الرياضيات (القسم العلمي) — الصف الثالث الثانوي',
            'authority' => 'common',
            'branch' => 'scientific',
            'source_url' => self::SOURCE_URL,
            'pages_count' => 259,
            'status' => 'published',
        ])->save();

        $this->archiveStructuralLessons($book);

        $order = 0;
        foreach ($this->curriculum() as $unit) {
            foreach ($unit['lessons'] as $lesson) {
                $order++;
                $this->writeLesson($book, $unit['title'], $lesson, $order);
            }
        }

        $this->seedGame($grade, $subject, $book);
    }

    /**
     * The structural placeholder lessons the catalogue seeder wrote for this
     * subject (recognisable by their "محتوى هيكلي" note) are archived so the
     * learner-facing book shows only the authored lessons. Nothing is deleted.
     */
    private function archiveStructuralLessons(Textbook $book): void
    {
        $lessons = Lesson::where('textbook_id', $book->id)->with('sections')->get();
        foreach ($lessons as $lesson) {
            $structural = $lesson->sections->contains(
                fn ($s) => is_string($s->content) && str_contains($s->content, 'محتوى هيكلي من فهرس المنهج'),
            );
            if ($structural) {
                $lesson->status = 'draft';
                $lesson->save();
            }
        }
    }

    /**
     * Write (or refresh) one authored lesson: sections, source pages, a summary
     * and its exercise set. Idempotent — re-running replaces the lesson's own
     * sections/exercises instead of duplicating them.
     *
     * @param  array{title: string, from: int, to: int, sections: array<int, array{type: string, content: string}>, exercises: array<int, array<string, mixed>>}  $lesson
     */
    private function writeLesson(Textbook $book, string $unit, array $lesson, int $order): void
    {
        $title = $lesson['title'];
        $from = $lesson['from'];
        $to = $lesson['to'];

        $model = Lesson::updateOrCreate(
            ['textbook_id' => $book->id, 'title' => $title],
            [
                'page_from' => $from,
                'page_to' => $to,
                'sort_order' => $order,
                'status' => 'published',
            ],
        );

        // Replace this lesson's own children so a re-run never duplicates them.
        $model->sections()->delete();
        $model->exercises()->delete();
        $model->summaries()->delete();

        $points = [];
        foreach ($lesson['sections'] as $i => $s) {
            $model->sections()->create([
                'type' => $s['type'],
                'title' => $s['title'] ?? null,
                'content' => $s['content'],
                'source_page' => $s['page'] ?? $from,
                'sort_order' => $i,
                'is_from_textbook' => true,
            ]);
            $points[] = ['text' => $s['content'], 'page' => $s['page'] ?? $from];
        }

        Summary::create([
            'lesson_id' => $model->id,
            'title' => 'ملخص: ' . $title,
            'points' => $points,
            'keywords' => $lesson['keywords'] ?? [],
            'source_pages' => array_values(array_unique(array_map(fn ($p) => $p['page'], $points))),
            'generated_by' => 'human',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        foreach ($lesson['exercises'] as $i => $e) {
            $ex = Exercise::create([
                'lesson_id' => $model->id,
                'type' => $e['type'],
                'question' => $e['q'],
                'answer' => $e['answer'] ?? null,
                'solution_steps' => $e['steps'] ?? null,
                'hint' => $e['hint'] ?? null,
                'source_page' => $e['page'] ?? $from,
                'difficulty' => $e['difficulty'] ?? 'easy',
                'sort_order' => $i,
                'source' => 'textbook',
                'verified_at' => now(),
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

        $byPage = [];
        foreach ($lesson['sections'] as $s) {
            $byPage[$s['page'] ?? $from][] = $s['content'];
        }
        for ($n = $from; $n <= $to; $n++) {
            TextbookPage::updateOrCreate(
                ['textbook_id' => $book->id, 'page_number' => $n],
                [
                    'extracted_text' => isset($byPage[$n]) ? implode("\n", $byPage[$n]) : null,
                    'status' => isset($byPage[$n]) ? 'reviewed' : 'raw',
                ],
            );
        }
    }

    /** A topic game for grade-12 maths, built from the book's own units. */
    private function seedGame(Grade $grade, Subject $subject, Textbook $book): void
    {
        $questions = [
            ['q' => 'ما قيمة الوحدة التخيلية ت² ؟', 'options' => ['-١', '١', 'صفر', 'ت'], 'answer' => '-١'],
            ['q' => 'ما مرافق العدد المركب (٣ + ٤ت) ؟', 'options' => ['٣ − ٤ت', '٤ + ٣ت', '−٣ − ٤ت', '٤ − ٣ت'], 'answer' => '٣ − ٤ت'],
            ['q' => 'ما الصورة الجبرية للعدد المركب ٥ − ٢ت ؟', 'options' => ['٥ + (−٢)ت', '٥ + ٢ت', '−٥ − ٢ت', '٢ − ٥ت'], 'answer' => '٥ + (−٢)ت'],
            ['q' => 'مبرهنة ذات الحدين تُستخدم في فكّ:', 'options' => ['(أ + ب)ⁿ', 'أ × ب', 'أ ÷ ب', 'أ − ب'], 'answer' => '(أ + ب)ⁿ'],
            ['q' => 'كم يساوي مضروب العدد ٣! ؟', 'options' => ['٦', '٣', '٩', '١٢'], 'answer' => '٦'],
            ['q' => 'إذا كان احتمال وقوع حادثة ٠٫٣ فإن احتمال عدم وقوعها يساوي:', 'options' => ['٠٫٧', '٠٫٣', '١', '٠٫٥'], 'answer' => '٠٫٧'],
            ['q' => 'معادلة القطع المكافئ الذي رأسه نقطة الأصل ومحوره ص-ص هي:', 'options' => ['س² = ٤جـ ص', 'ص² = ٤جـ س', 'س² + ص² = ١', 'س ص = ١'], 'answer' => 'س² = ٤جـ ص'],
            ['q' => 'ما معادلة القطع الناقص الذي مركزه نقطة الأصل ومحوره الأكبر على محور السينات ؟', 'options' => ['س²/أ² + ص²/ب² = ١', 'س² − ص² = ١', 'ص² = ٤جـ س', 'س + ص = ١'], 'answer' => 'س²/أ² + ص²/ب² = ١'],
            ['q' => 'مشتقة الدالة ص = س³ هي:', 'options' => ['٣س²', 'س²', '٣س', 'س⁴/٤'], 'answer' => '٣س²'],
            ['q' => 'مشتقة الدالة اللوغاريتمية ص = لو س هي:', 'options' => ['١/س', 'س', 'لو س', '١'], 'answer' => '١/س'],
            ['q' => 'تكامل ١/س بالنسبة إلى س يساوي:', 'options' => ['لو |س| + ث', 'س²/٢ + ث', '−١/س² + ث', 'س + ث'], 'answer' => 'لو |س| + ث'],
            ['q' => 'تُحسب مساحة المنطقة المستوية تحت المنحنى باستخدام:', 'options' => ['التكامل المحدود', 'المشتقة', 'المحددات', 'المتجهات'], 'answer' => 'التكامل المحدود'],
        ];

        GameActivity::updateOrCreate(
            ['grade_id' => $grade->id, 'subject_id' => $subject->id],
            [
                'title' => 'لعبة الرياضيات — الصف الثالث الثانوي (علمي)',
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'lesson_id' => Lesson::where('textbook_id', $book->id)->where('status', 'published')->value('id'),
                'type' => 'math',
                'difficulty' => 3,
                'points' => 25,
                'config' => ['questions' => $questions],
            ],
        );
    }

    /**
     * The authored curriculum: seven units, each with its lessons, sections and
     * exercises. Content follows the official scientific-branch book.
     *
     * @return array<int, array{title: string, lessons: array<int, array<string, mixed>>}>
     */
    private function curriculum(): array
    {
        return [
            [
                'title' => 'الوحدة الأولى: الأعداد المركبة',
                'lessons' => [
                    [
                        'title' => 'مجموعة الأعداد المركبة وصورتها الجبرية',
                        'from' => 9, 'to' => 16,
                        'keywords' => ['الأعداد المركبة', 'الوحدة التخيلية', 'الصورة الجبرية'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'مجموعة الأعداد المركبة', 'page' => 9],
                            ['type' => 'definition', 'content' => 'الوحدة التخيلية: العدد ت هو الجذر التربيعي للعدد (−١)، أي ت² = −١، ويُسمى العدد ت الوحدة التخيلية.', 'page' => 10],
                            ['type' => 'definition', 'content' => 'العدد المركب: هو كل عدد على الصورة (س + ت ص)، حيث س، ص عددان حقيقيان؛ س هو الجزء الحقيقي، وص هو الجزء التخيلي.', 'page' => 11],
                            ['type' => 'point', 'content' => 'مجموعة الأعداد المركبة (ك) تشمل مجموعة الأعداد الحقيقية (ح)؛ فكل عدد حقيقي هو عدد مركب جزؤه التخيلي صفر.', 'page' => 12],
                            ['type' => 'example', 'content' => 'مثال: العدد ٣ + ٤ت عدد مركب جزؤه الحقيقي ٣ وجزؤه التخيلي ٤.', 'page' => 13],
                            ['type' => 'note', 'content' => 'قوتان للعدد ت تتكرران: ت¹ = ت، ت² = −١، ت³ = −ت، ت⁴ = ١، ثم تتكرر الدورة كل أربع قوى.', 'page' => 15],
                        ],
                        'exercises' => [
                            ['type' => 'mcq', 'q' => 'ما قيمة ت² ؟', 'answer' => '−١', 'page' => 10,
                                'options' => [['text' => '−١', 'correct' => true], ['text' => '١'], ['text' => 'صفر']],
                                'hint' => 'تعرّف الوحدة التخيلية: ت² = −١.',
                                'steps' => ['الوحدة التخيلية ت هي الجذر التربيعي للعدد −١.', 'إذن ت² = −١.']],
                            ['type' => 'mcq', 'q' => 'العدد ٥ − ٢ت، ما جزؤه التخيلي ؟', 'answer' => '−٢', 'page' => 11,
                                'options' => [['text' => '−٢', 'correct' => true], ['text' => '٢'], ['text' => '٥']],
                                'hint' => 'الجزء التخيلي هو معامل ت.',
                                'steps' => ['الصورة (س + ت ص) وفيها الجزء التخيلي ص.', 'هنا ص = −٢.']],
                            ['type' => 'numeric', 'q' => 'احسب قيمة ت⁴ (اكتب الناتج)', 'answer' => '1', 'page' => 15,
                                'hint' => 'ت² = −١، إذن ت⁴ = (ت²)² .',
                                'steps' => ['ت⁴ = (ت²)².', '(ت²)² = (−١)² = ١.', 'الناتج ١.']],
                        ],
                    ],
                    [
                        'title' => 'جمع وضرب الأعداد المركبة',
                        'from' => 17, 'to' => 24,
                        'keywords' => ['الجمع', 'الضرب', 'الأعداد المركبة'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'العمليات على الأعداد المركبة', 'page' => 17],
                            ['type' => 'definition', 'content' => 'الجمع والطرح: نجمع (نطرح) الجزأين الحقيقيين معًا، والجزأين التخيليين معًا: (أ + ت ب) + (جـ + ت د) = (أ + جـ) + ت (ب + د).', 'page' => 18],
                            ['type' => 'definition', 'content' => 'الضرب: نضرب كما نضرب الحدود الجبرية ثم نعوّض ت² = −١: (أ + ت ب)(جـ + ت د) = (أ جـ − ب د) + ت (أ د + ب جـ).', 'page' => 20],
                            ['type' => 'example', 'content' => 'مثال: (٢ + ٣ت) + (١ − ٥ت) = (٢ + ١) + ت(٣ − ٥) = ٣ − ٢ت.', 'page' => 19],
                            ['type' => 'example', 'content' => 'مثال: (٢ + ت)(٣ + ٤ت) = ٦ + ٨ت + ٣ت + ٤ت² = ٦ + ١١ت − ٤ = ٢ + ١١ت.', 'page' => 21],
                            ['type' => 'note', 'content' => 'الضرب في الأعداد المركبة إبدالي وتجميعي، ويقبل التوزيع، تمامًا كالأعداد الحقيقية.', 'page' => 23],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'ما الجزء الحقيقي لناتج (١ + ٢ت) + (٣ + ٤ت) ؟', 'answer' => '4', 'page' => 18,
                                'hint' => 'اجمع الجزأين الحقيقيين: ١ + ٣.',
                                'steps' => ['نجمع الجزأين الحقيقيين: ١ + ٣ = ٤.', 'الجزء الحقيقي للناتج ٤.']],
                            ['type' => 'mcq', 'q' => 'ما ناتج (٢ + ت)(٢ − ت) ؟', 'answer' => '٥', 'page' => 21,
                                'options' => [['text' => '٥', 'correct' => true], ['text' => '٣'], ['text' => '٤ − ت']],
                                'hint' => 'استخدم (أ + ت ب)(أ − ت ب) = أ² + ب².',
                                'steps' => ['(٢ + ت)(٢ − ت) = ٢² + ١².', '= ٤ + ١ = ٥.']],
                            ['type' => 'short_answer', 'q' => 'اكتب ناتج ٣ت × ٢ت بعد التبسيط.', 'answer' => '−٦', 'page' => 20,
                                'hint' => 'تذكّر أن ت² = −١.',
                                'steps' => ['٣ت × ٢ت = ٦ت².', 'ت² = −١، إذن ٦ × (−١) = −٦.']],
                        ],
                    ],
                    [
                        'title' => 'القسمة والمرافق والصورة القطبية',
                        'from' => 25, 'to' => 34,
                        'keywords' => ['المرافق', 'القسمة', 'الصورة القطبية', 'المعيار'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'القسمة والمرافق', 'page' => 25],
                            ['type' => 'definition', 'content' => 'مرافق العدد المركب (س + ت ص) هو (س − ت ص)، ونحصل عليه بتغيير إشارة الجزء التخيلي فقط.', 'page' => 25],
                            ['type' => 'point', 'content' => 'للقسمة نضرب البسط والمقام في مرافق المقام، لأن حاصل ضرب العدد في مرافقه عدد حقيقي: (س + ت ص)(س − ت ص) = س² + ص².', 'page' => 26],
                            ['type' => 'definition', 'content' => 'معيار (مقياس) العدد المركب ع = س + ت ص هو |ع| = الجذر التربيعي لـ (س² + ص²).', 'page' => 28],
                            ['type' => 'definition', 'content' => 'الصورة القطبية: ع = |ع| (جتا هـ + ت جا هـ)، حيث هـ زاوية العدد المركب، و |ع| معياره.', 'page' => 30],
                            ['type' => 'example', 'content' => 'مثال: معيار العدد ٣ + ٤ت هو |٣ + ٤ت| = √(٩ + ١٦) = √٢٥ = ٥.', 'page' => 29],
                            ['type' => 'note', 'content' => 'في ضرب الأعداد المركبة بالصورة القطبية تُضرب المعايير وتُجمع الزوايا.', 'page' => 33],
                        ],
                        'exercises' => [
                            ['type' => 'mcq', 'q' => 'ما مرافق العدد (٣ + ٤ت) ؟', 'answer' => '٣ − ٤ت', 'page' => 25,
                                'options' => [['text' => '٣ − ٤ت', 'correct' => true], ['text' => '−٣ + ٤ت'], ['text' => '٤ + ٣ت']],
                                'hint' => 'غيّر إشارة الجزء التخيلي فقط.',
                                'steps' => ['المرافق يُغيّر إشارة الجزء التخيلي.', 'إذن مرافق (٣ + ٤ت) هو (٣ − ٤ت).']],
                            ['type' => 'numeric', 'q' => 'ما معيار العدد المركب (٦ + ٨ت) ؟', 'answer' => '10', 'page' => 28,
                                'hint' => 'المعيار = √(س² + ص²).',
                                'steps' => ['|٦ + ٨ت| = √(٦² + ٨²).', '= √(٣٦ + ٦٤) = √١٠٠.', '= ١٠.']],
                            ['type' => 'short_answer', 'q' => 'اكتب العدد ت بالصورة القطبية.', 'answer' => 'جتا ٩٠ + ت جا ٩٠', 'page' => 30,
                                'hint' => 'ت تقع على محور الصادات الموجب، وزاويتها ٩٠°.',
                                'steps' => ['معيار ت هو ١.', 'زاوية ت هي ٩٠°.', 'الصورة القطبية: ١(جتا ٩٠ + ت جا ٩٠).']],
                        ],
                    ],
                ],
            ],

            [
                'title' => 'الوحدة الثانية: مبدأ العدّ ومبرهنة ذات الحدين',
                'lessons' => [
                    [
                        'title' => 'مبدأ العدّ',
                        'from' => 35, 'to' => 42,
                        'keywords' => ['مبدأ العدّ', 'الاحتمالات'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'مبدأ العدّ', 'page' => 35],
                            ['type' => 'definition', 'content' => 'إذا أمكن إتمام عملية بطريقة من م طريقة، وعملية ثانية بطريقة من ن طريقة، فإن إتمام العمليتين معًا يمكن بـ (م × ن) طريقة.', 'page' => 36],
                            ['type' => 'example', 'content' => 'مثال: إذا كان لدينا ٣ قمصان و٤ بناطيل، فعدد الملابس المختلفة = ٣ × ٤ = ١٢ طريقة.', 'page' => 38],
                            ['type' => 'point', 'content' => 'مبدأ العدّ الأساسي ينطبق على أي عدد من العمليات المتتالية، لا عمليتين فقط.', 'page' => 39],
                            ['type' => 'note', 'content' => 'مضروب العدد ن! = ن × (ن − ١) × ... × ٢ × ١، و ٠! = ١.', 'page' => 41],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'إذا كان لديك ٤ ألوان و٣ أشكال، فبكم طريقة يمكن تكوين تصميم واحد ؟', 'answer' => '12', 'page' => 36,
                                'hint' => 'اضرب عدد الطرق: ٤ × ٣.',
                                'steps' => ['مبدأ العدّ: اضرب عدد الخيارات.', '٤ × ٣ = ١٢ طريقة.']],
                            ['type' => 'numeric', 'q' => 'احسب ٤!', 'answer' => '24', 'page' => 41,
                                'hint' => '٤! = ٤ × ٣ × ٢ × ١.',
                                'steps' => ['٤! = ٤ × ٣ × ٢ × ١.', '= ٢٤.']],
                            ['type' => 'mcq', 'q' => 'كم يساوي ٠! ؟', 'answer' => '١', 'page' => 41,
                                'options' => [['text' => '١', 'correct' => true], ['text' => '٠'], ['text' => 'غير معرّف']],
                                'hint' => 'بالتعريف ٠! = ١.',
                                'steps' => ['مضروب الصفر يُعرَّف بأنه ١.', 'إذن ٠! = ١.']],
                        ],
                    ],
                    [
                        'title' => 'التباديل والتوافيق',
                        'from' => 43, 'to' => 50,
                        'keywords' => ['التباديل', 'التوافيق'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'التباديل والتوافيق', 'page' => 43],
                            ['type' => 'definition', 'content' => 'التبديل: ترتيب مجموعة من العناصر بحيث يهمّ الترتيب، ويُحسب بـ ن ل ر = ن! ÷ (ن − ر)!.', 'page' => 44],
                            ['type' => 'definition', 'content' => 'التوفيق: اختيار مجموعة من العناصر دون اعتبار للترتيب، ويُحسب بـ ن ق ر = ن! ÷ (ر! × (ن − ر)!).', 'page' => 46],
                            ['type' => 'point', 'content' => 'الفرق الجوهري: في التباديل يهمّ الترتيب، وفي التوافيق لا يهمّ الترتيب.', 'page' => 47],
                            ['type' => 'example', 'content' => 'مثال: عدد طرق اختيار ٢ من ٥ أشخاص (التوافيق) = ٥ ق ٢ = ١٠ طرق.', 'page' => 48],
                            ['type' => 'note', 'content' => 'ن ق ٠ = ١ و ن ق ن = ١ دائمًا.', 'page' => 49],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'احسب ٥ ق ٢ (التوافيق).', 'answer' => '10', 'page' => 46,
                                'hint' => '٥ ق ٢ = ٥! ÷ (٢! × ٣!).',
                                'steps' => ['٥ ق ٢ = ٥! ÷ (٢! × ٣!).', '= (٥ × ٤) ÷ ٢ = ١٠.']],
                            ['type' => 'numeric', 'q' => 'احسب ٣ ل ٣ (التباديل).', 'answer' => '6', 'page' => 44,
                                'hint' => '٣ ل ٣ = ٣!.',
                                'steps' => ['٣ ل ٣ = ٣! ÷ ٠!.', '= ٦ ÷ ١ = ٦.']],
                            ['type' => 'mcq', 'q' => 'أيّهما يهمّ فيه الترتيب ؟', 'answer' => 'التبديل', 'page' => 47,
                                'options' => [['text' => 'التبديل', 'correct' => true], ['text' => 'التوفيق'], ['text' => 'كلاهما لا يهمّ']],
                                'hint' => 'راجع الفرق بين التبديل والتوفيق.',
                                'steps' => ['في التبديل يهمّ الترتيب.', 'في التوفيق لا يهمّ الترتيب.']],
                        ],
                    ],
                    [
                        'title' => 'مبرهنة ذات الحدين',
                        'from' => 51, 'to' => 58,
                        'keywords' => ['ذات الحدين', 'معاملات', 'باسكال'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'مبرهنة ذات الحدين', 'page' => 51],
                            ['type' => 'definition', 'content' => '(أ + ب)ⁿ = أⁿ + ن ق ١ أ^(ن−١) ب + ن ق ٢ أ^(ن−٢) ب² + ... + بⁿ، والمعاملات هي توافيق.', 'page' => 52],
                            ['type' => 'point', 'content' => 'معاملات مفكوك (أ + ب)ⁿ تُرتَّب في مثلث باسكال، وكل معامل يساوي مجموع المعاملين فوقه.', 'page' => 54],
                            ['type' => 'example', 'content' => 'مثال: (س + ص)² = س² + ٢س ص + ص²، والمعاملات ١، ٢، ١.', 'page' => 53],
                            ['type' => 'example', 'content' => 'مثال: (س + ص)³ = س³ + ٣س²ص + ٣س ص² + ص³، والمعاملات ١، ٣، ٣، ١.', 'page' => 55],
                            ['type' => 'note', 'content' => 'عدد حدود المفكوك (ن + ١) حدًا.', 'page' => 57],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'كم عدد حدود مفكوك (أ + ب)⁴ ؟', 'answer' => '5', 'page' => 57,
                                'hint' => 'عدد الحدود = ن + ١.',
                                'steps' => ['عدد الحدود = ن + ١.', '= ٤ + ١ = ٥ حدود.']],
                            ['type' => 'mcq', 'q' => 'ما معامل الحد الأوسط في مفكوك (س + ص)² ؟', 'answer' => '٢', 'page' => 53,
                                'options' => [['text' => '٢', 'correct' => true], ['text' => '١'], ['text' => '٣']],
                                'hint' => 'المعاملات ١، ٢، ١.',
                                'steps' => ['(س + ص)² = س² + ٢س ص + ص².', 'المعامل الأوسط هو ٢.']],
                            ['type' => 'short_answer', 'q' => 'اكتب مفكوك (س + ١)² .', 'answer' => 'س² + ٢س + ١', 'page' => 53,
                                'hint' => 'استخدم (أ + ب)² = أ² + ٢أ ب + ب².',
                                'steps' => ['(س + ١)² = س² + ٢(س)(١) + ١².', '= س² + ٢س + ١.']],
                        ],
                    ],
                ],
            ],

            [
                'title' => 'الوحدة الثالثة: الاحتمالات',
                'lessons' => [
                    [
                        'title' => 'الاحتمال الشرطي وقانون الضرب',
                        'from' => 59, 'to' => 66,
                        'keywords' => ['الاحتمال الشرطي', 'قانون الضرب'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'الاحتمال الشرطي', 'page' => 59],
                            ['type' => 'definition', 'content' => 'الاحتمال الشرطي: احتمال وقوع الحادثة (أ) بشرط وقوع الحادثة (ب) هو ح(أ|ب) = ح(أ ∩ ب) ÷ ح(ب)، بشرط ح(ب) > ٠.', 'page' => 60],
                            ['type' => 'definition', 'content' => 'قانون الضرب: ح(أ ∩ ب) = ح(ب) × ح(أ|ب).', 'page' => 62],
                            ['type' => 'point', 'content' => 'احتمال أي حادثة عدد بين ٠ و١، ومجموع احتمالات جميع النواتج = ١.', 'page' => 63],
                            ['type' => 'example', 'content' => 'مثال: إذا كان ح(ب) = ٠٫٥ و ح(أ|ب) = ٠٫٤ فإن ح(أ ∩ ب) = ٠٫٥ × ٠٫٤ = ٠٫٢.', 'page' => 64],
                            ['type' => 'note', 'content' => 'احتمال الحادثة المتممة: ح(أ´) = ١ − ح(أ).', 'page' => 65],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'إذا كان ح(أ) = ٠٫٣، فما احتمال الحادثة المتممة ؟ (اكتب بالصيغة 0.7)', 'answer' => '0.7', 'page' => 65,
                                'hint' => 'ح(أ´) = ١ − ح(أ).',
                                'steps' => ['ح(أ´) = ١ − ٠٫٣.', '= ٠٫٧.']],
                            ['type' => 'mcq', 'q' => 'ما مدى قيمة أي احتمال ؟', 'answer' => 'بين ٠ و١', 'page' => 63,
                                'options' => [['text' => 'بين ٠ و١', 'correct' => true], ['text' => 'أكبر من ١'], ['text' => 'أي عدد']],
                                'hint' => 'الاحتمال لا يقل عن ٠ ولا يزيد عن ١.',
                                'steps' => ['الاحتمال عدد بين ٠ و١ شاملًا.', 'إذن الجواب: بين ٠ و١.']],
                            ['type' => 'short_answer', 'q' => 'اكتب قانون الضرب للاحتمال.', 'answer' => 'ح(أ ∩ ب) = ح(ب) × ح(أ|ب)', 'page' => 62,
                                'hint' => 'اربط الاحتمال الشرطي بحاصل الضرب.',
                                'steps' => ['قانون الضرب: ح(أ ∩ ب) = ح(ب) × ح(أ|ب).']],
                        ],
                    ],
                    [
                        'title' => 'الحوادث المستقلة',
                        'from' => 67, 'to' => 72,
                        'keywords' => ['الحوادث المستقلة'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'الحوادث المستقلة', 'page' => 67],
                            ['type' => 'definition', 'content' => 'الحادثتان (أ) و(ب) مستقلتان إذا لم يؤثر وقوع إحداهما في احتمال الأخرى، أي ح(أ|ب) = ح(أ).', 'page' => 68],
                            ['type' => 'point', 'content' => 'إذا كانت (أ) و(ب) مستقلتين فإن ح(أ ∩ ب) = ح(أ) × ح(ب).', 'page' => 69],
                            ['type' => 'example', 'content' => 'مثال: إلقاء قطعة نقود مرتين حادثتان مستقلتان؛ احتمال الصورة في المرة الثانية لا يتأثر بالمرة الأولى.', 'page' => 70],
                            ['type' => 'note', 'content' => 'لا تخلط بين الحوادث المستقلة والحوادث المتنافية؛ المتنافية لا تقع معًا، والمستقلة لا تؤثر إحداهما في الأخرى.', 'page' => 71],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'أُلقيت قطعة نقود مرتين. ما احتمال ظهور صورة في المرتين ؟ (اكتب 0.25)', 'answer' => '0.25', 'page' => 69,
                                'hint' => 'ح(صورة) = ٠٫٥، والحدثان مستقلان.',
                                'steps' => ['ح(صورة) = ١/٢ = ٠٫٥.', 'ح(صورة و صورة) = ٠٫٥ × ٠٫٥.', '= ٠٫٢٥.']],
                            ['type' => 'mcq', 'q' => 'الحادثتان المتنافيتان هما التي:', 'answer' => 'لا تقعان معًا', 'page' => 71,
                                'options' => [['text' => 'لا تقعان معًا', 'correct' => true], ['text' => 'لا تؤثر إحداهما في الأخرى'], ['text' => 'متساويتان']],
                                'hint' => 'تذكّر تعريف التنافي.',
                                'steps' => ['الحوادث المتنافية لا يمكن وقوعها معًا.', 'إذن الجواب: لا تقعان معًا.']],
                            ['type' => 'short_answer', 'q' => 'اكتب شرط استقلال الحادثتين (أ) و(ب).', 'answer' => 'ح(أ ∩ ب) = ح(أ) × ح(ب)', 'page' => 69,
                                'hint' => 'اربط حاصل الضرب بالاستقلال.',
                                'steps' => ['تكون الحادثتان مستقلتين إذا كان ح(أ ∩ ب) = ح(أ) × ح(ب).']],
                        ],
                    ],
                    [
                        'title' => 'التكرارات المستقلة وقانون الاحتمال الثنائي',
                        'from' => 73, 'to' => 80,
                        'keywords' => ['التوزيع الثنائي', 'التكرارات المستقلة'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'متتاليات التكرارات المستقلة', 'page' => 73],
                            ['type' => 'definition', 'content' => 'التجربة التي تُكرَّر عددًا من المرات بحيث تكون النتائج مستقلة، ولها ناتجان فقط (نجاح/فشل)، تُسمى تجربة ثنائية.', 'page' => 74],
                            ['type' => 'definition', 'content' => 'قانون الاحتمال الثنائي: ح(ر نجاح من ن محاولة) = ن ق ر × (ح)^ر × (١ − ح)^(ن − ر).', 'page' => 76],
                            ['type' => 'example', 'content' => 'مثال: إلقاء قطعة نقود ٣ مرات؛ احتمال ظهور صورتين بالضبط = ٣ ق ٢ × (٠٫٥)² × (٠٫٥)¹ = ٣ × ٠٫١٢٥ = ٠٫٣٧٥.', 'page' => 78],
                            ['type' => 'note', 'content' => 'احتمال النجاح يُرمز له بـ ح، واحتمال الفشل (١ − ح).', 'page' => 79],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'أُلقيت قطعة نقود ٣ مرات. ما احتمال ظهور صورة واحدة بالضبط ؟ (اكتب 0.375)', 'answer' => '0.375', 'page' => 78,
                                'hint' => 'استخدم ن ق ر × ح^ر × (١−ح)^(ن−ر) مع ن=٣، ر=١، ح=٠٫٥.',
                                'steps' => ['٣ ق ١ = ٣.', 'ح^١ = ٠٫٥، (١−ح)^٢ = ٠٫٢٥.', 'الناتج = ٣ × ٠٫٥ × ٠٫٢٥ = ٠٫٣٧٥.']],
                            ['type' => 'mcq', 'q' => 'التجربة الثنائية لها:', 'answer' => 'ناتجان فقط', 'page' => 74,
                                'options' => [['text' => 'ناتجان فقط', 'correct' => true], ['text' => 'ثلاثة نواتج'], ['text' => 'نواتج لا نهائية']],
                                'hint' => 'كلمة «ثنائي» تشير إلى ناتجين.',
                                'steps' => ['التجربة الثنائية ناتجاها: نجاح أو فشل.', 'إذن الجواب: ناتجان فقط.']],
                            ['type' => 'short_answer', 'q' => 'اكتب قانون الاحتمال الثنائي.', 'answer' => 'ن ق ر × ح^ر × (١ − ح)^(ن − ر)', 'page' => 76,
                                'hint' => 'اربط التوافيق باحتمالي النجاح والفشل.',
                                'steps' => ['ح(ر نجاح) = ن ق ر × ح^ر × (١ − ح)^(ن − ر).']],
                        ],
                    ],
                    [
                        'title' => 'السحب مع الإعادة وبدون إعادة',
                        'from' => 81, 'to' => 88,
                        'keywords' => ['السحب', 'مع الإعادة', 'بدون إعادة'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'السحب مع الإعادة وبدون إعادة', 'page' => 81],
                            ['type' => 'definition', 'content' => 'السحب مع الإعادة: نعيد العنصر إلى المجموعة بعد سحبه، فتبقى الاحتمالات ثابتة وتكون الحوادث مستقلة.', 'page' => 82],
                            ['type' => 'definition', 'content' => 'السحب بدون إعادة: لا نعيد العنصر، فيتغيّر عدد عناصر المجموعة والاحتمالات من سحبة إلى أخرى.', 'page' => 84],
                            ['type' => 'example', 'content' => 'مثال: صندوق فيه ٣ كرات حمراء و٢ زرقاء. احتمال سحب حمراء مرتين مع الإعادة = (٣/٥) × (٣/٥) = ٩/٢٥.', 'page' => 86],
                            ['type' => 'note', 'content' => 'بدون إعادة: احتمال الحمراء في السحبة الثانية = ٢/٤ إذا سُحبت حمراء أولًا.', 'page' => 87],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'صندوق فيه ٣ حمراء و٢ زرقاء. ما احتمال سحب حمراء مرتين مع الإعادة ؟ (اكتب 0.36)', 'answer' => '0.36', 'page' => 86,
                                'hint' => 'الاحتمال ثابت مع الإعادة: (٣/٥) × (٣/٥).',
                                'steps' => ['ح(حمراء) = ٣/٥ = ٠٫٦.', 'مع الإعادة تتكرر نفس النسبة.', '٠٫٦ × ٠٫٦ = ٠٫٣٦.']],
                            ['type' => 'mcq', 'q' => 'في السحب بدون إعادة:', 'answer' => 'تتغيّر الاحتمالات', 'page' => 84,
                                'options' => [['text' => 'تتغيّر الاحتمالات', 'correct' => true], ['text' => 'تبقى الاحتمالات ثابتة'], ['text' => 'لا علاقة']],
                                'hint' => 'يقلّ عدد عناصر المجموعة بعد كل سحب.',
                                'steps' => ['بدون إعادة يقلّ عدد العناصر.', 'إذن تتغيّر الاحتمالات.']],
                            ['type' => 'short_answer', 'q' => 'متى تكون الحوادث في السحب مستقلة ؟', 'answer' => 'عند السحب مع الإعادة', 'page' => 82,
                                'hint' => 'الاستقلال مرتبط بثبات الاحتمالات.',
                                'steps' => ['مع الإعادة تبقى الاحتمالات ثابتة.', 'إذن الحوادث مستقلة عند السحب مع الإعادة.']],
                        ],
                    ],
                ],
            ],

            [
                'title' => 'الوحدة الرابعة: القطوع المخروطية',
                'lessons' => [
                    [
                        'title' => 'القطع المكافئ',
                        'from' => 89, 'to' => 96,
                        'keywords' => ['القطع المكافئ', 'البؤرة', 'الدليل'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'القطع المكافئ', 'page' => 89],
                            ['type' => 'definition', 'content' => 'القطع المكافئ: مجموعة النقاط التي تبعد بُعدًا متساويًا عن نقطة ثابتة (البؤرة) وعن مستقيم ثابت (الدليل).', 'page' => 90],
                            ['type' => 'definition', 'content' => 'معادلة القطع المكافئ الذي رأسه نقطة الأصل ومحوره محور السينات: ص² = ٤ جـ س، والبؤرة (جـ، ٠).', 'page' => 92],
                            ['type' => 'definition', 'content' => 'معادلة القطع المكافئ الذي رأسه نقطة الأصل ومحوره محور الصادات: س² = ٤ جـ ص، والبؤرة (٠، جـ).', 'page' => 93],
                            ['type' => 'example', 'content' => 'مثال: ص² = ٨س قطع مكافئ، ومنه ٤جـ = ٨ إذن جـ = ٢، والبؤرة (٢، ٠).', 'page' => 94],
                            ['type' => 'note', 'content' => 'الدليل مستقيم معادلته س = −جـ عندما يكون المحور محور السينات.', 'page' => 95],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'في القطع المكافئ ص² = ١٢س، ما قيمة جـ ؟', 'answer' => '3', 'page' => 94,
                                'hint' => 'قارن بـ ص² = ٤جـ س، إذن ٤جـ = ١٢.',
                                'steps' => ['ص² = ٤جـ س.', '٤جـ = ١٢.', 'جـ = ٣.']],
                            ['type' => 'mcq', 'q' => 'ما معادلة القطع المكافئ الذي محوره محور الصادات ورأسه نقطة الأصل ؟', 'answer' => 'س² = ٤جـ ص', 'page' => 93,
                                'options' => [['text' => 'س² = ٤جـ ص', 'correct' => true], ['text' => 'ص² = ٤جـ س'], ['text' => 'س² + ص² = ١']],
                                'hint' => 'المحور الصادات يعني أن المتغير المربّع هو س.',
                                'steps' => ['عندما يكون المحور محور الصادات: س² = ٤جـ ص.']],
                            ['type' => 'short_answer', 'q' => 'اكتب معادلة القطع المكافئ الذي بؤرته (٣، ٠) ورأسه نقطة الأصل.', 'answer' => 'ص² = ١٢س', 'page' => 94,
                                'hint' => 'جـ = ٣، والمعادلة ص² = ٤جـ س.',
                                'steps' => ['البؤرة (جـ، ٠) إذن جـ = ٣.', 'ص² = ٤ × ٣ × س.', '= ١٢س.']],
                        ],
                    ],
                    [
                        'title' => 'القطع الناقص',
                        'from' => 97, 'to' => 104,
                        'keywords' => ['القطع الناقص', 'المحور الأكبر', 'البؤرتان'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'القطع الناقص', 'page' => 97],
                            ['type' => 'definition', 'content' => 'القطع الناقص: مجموعة النقاط التي يكون مجموع بعديها عن بؤرتين ثابتًا.', 'page' => 98],
                            ['type' => 'definition', 'content' => 'معادلة القطع الناقص الذي مركزه نقطة الأصل ومحوره الأكبر على محور السينات: س²/أ² + ص²/ب² = ١، حيث أ > ب.', 'page' => 100],
                            ['type' => 'point', 'content' => 'العلاقة بين العناصر: جـ² = أ² − ب²، والبؤرتان (±جـ، ٠).', 'page' => 101],
                            ['type' => 'example', 'content' => 'مثال: القطع الناقص س²/٢٥ + ص²/٩ = ١؛ أ² = ٢٥، ب² = ٩، جـ² = ١٦، جـ = ٤، والبؤرتان (±٤، ٠).', 'page' => 102],
                            ['type' => 'note', 'content' => 'إذا كان المحور الأكبر على محور الصادات تصبح المعادلة ص²/أ² + س²/ب² = ١.', 'page' => 103],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'في القطع الناقص س²/٢٥ + ص²/٩ = ١، ما قيمة جـ ؟', 'answer' => '4', 'page' => 101,
                                'hint' => 'جـ² = أ² − ب².',
                                'steps' => ['أ² = ٢٥، ب² = ٩.', 'جـ² = ٢٥ − ٩ = ١٦.', 'جـ = ٤.']],
                            ['type' => 'mcq', 'q' => 'ما مجموع بعدي أي نقطة على القطع الناقص عن البؤرتين ؟', 'answer' => 'ثابت', 'page' => 98,
                                'options' => [['text' => 'ثابت', 'correct' => true], ['text' => 'متغيّر'], ['text' => 'صفر']],
                                'hint' => 'هذا هو التعريف الأساسي للقطع الناقص.',
                                'steps' => ['القطع الناقص: مجموع البعدين عن البؤرتين ثابت.']],
                            ['type' => 'short_answer', 'q' => 'اكتب العلاقة بين أ، ب، جـ في القطع الناقص.', 'answer' => 'جـ² = أ² − ب²', 'page' => 101,
                                'hint' => 'جـ أصغر من أ، والعلاقة تربط المربّعات.',
                                'steps' => ['في القطع الناقص: جـ² = أ² − ب².']],
                        ],
                    ],
                    [
                        'title' => 'القطع الزائد',
                        'from' => 105, 'to' => 112,
                        'keywords' => ['القطع الزائد', 'البؤرتان'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'القطع الزائد', 'page' => 105],
                            ['type' => 'definition', 'content' => 'القطع الزائد: مجموعة النقاط التي يكون الفرق المطلق بين بعديها عن بؤرتين ثابتًا.', 'page' => 106],
                            ['type' => 'definition', 'content' => 'معادلة القطع الزائد الذي مركزه نقطة الأصل ومحوره الحقيقي على محور السينات: س²/أ² − ص²/ب² = ١.', 'page' => 108],
                            ['type' => 'point', 'content' => 'العلاقة بين العناصر: جـ² = أ² + ب²، والبؤرتان (±جـ، ٠).', 'page' => 109],
                            ['type' => 'example', 'content' => 'مثال: س²/٩ − ص²/١٦ = ١؛ أ² = ٩، ب² = ١٦، جـ² = ٢٥، جـ = ٥.', 'page' => 110],
                            ['type' => 'note', 'content' => 'القطع الزائد له فرعان، بخلاف القطع الناقص المغلق.', 'page' => 111],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'في القطع الزائد س²/٩ − ص²/١٦ = ١، ما قيمة جـ ؟', 'answer' => '5', 'page' => 109,
                                'hint' => 'جـ² = أ² + ب².',
                                'steps' => ['أ² = ٩، ب² = ١٦.', 'جـ² = ٩ + ١٦ = ٢٥.', 'جـ = ٥.']],
                            ['type' => 'mcq', 'q' => 'في القطع الزائد يكون الفرق بين البعدين عن البؤرتين:', 'answer' => 'ثابتًا', 'page' => 106,
                                'options' => [['text' => 'ثابتًا', 'correct' => true], ['text' => 'متغيّرًا'], ['text' => 'يساوي صفرًا']],
                                'hint' => 'راجع تعريف القطع الزائد.',
                                'steps' => ['القطع الزائد: الفرق المطلق بين البعدين ثابت.']],
                            ['type' => 'short_answer', 'q' => 'اكتب العلاقة بين أ، ب، جـ في القطع الزائد.', 'answer' => 'جـ² = أ² + ب²', 'page' => 109,
                                'hint' => 'لاحظ الفرق عن القطع الناقص (هنا جمع).',
                                'steps' => ['في القطع الزائد: جـ² = أ² + ب².']],
                        ],
                    ],
                    [
                        'title' => 'دوران المحاور الإحداثية',
                        'from' => 113, 'to' => 120,
                        'keywords' => ['دوران المحاور', 'الإحداثيات'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'دوران المحاور الإحداثية', 'page' => 113],
                            ['type' => 'definition', 'content' => 'دوران المحاور: تغيير اتجاه محوري السينات والصادات بزاوية هـ دون تغيير نقطة الأصل.', 'page' => 114],
                            ['type' => 'definition', 'content' => 'علاقات الدوران: س = سَ جتا هـ − صَ جا هـ، ص = سَ جا هـ + صَ جتا هـ.', 'page' => 116],
                            ['type' => 'point', 'content' => 'يُستخدم الدوران لتبسيط معادلة قطع مخروطي بإزالة الحد (س ص).', 'page' => 117],
                            ['type' => 'example', 'content' => 'مثال: لزاوية دوران ٤٥° نعوّض جتا ٤٥ = جا ٤٥ = √٢/٢ في علاقات الدوران.', 'page' => 118],
                            ['type' => 'note', 'content' => 'زاوية الدوران تُختار بحيث يختفي الحد المختلط (س ص) من المعادلة.', 'page' => 119],
                        ],
                        'exercises' => [
                            ['type' => 'mcq', 'q' => 'ما الهدف من دوران المحاور الإحداثية ؟', 'answer' => 'تبسيط معادلة القطع المخروطي', 'page' => 117,
                                'options' => [['text' => 'تبسيط معادلة القطع المخروطي', 'correct' => true], ['text' => 'تغيير نقطة الأصل'], ['text' => 'حساب المساحة']],
                                'hint' => 'راجع فائدة الدوران.',
                                'steps' => ['الدوران يزيل الحد المختلط (س ص).', 'إذن يبسّط المعادلة.']],
                            ['type' => 'numeric', 'q' => 'ما قيمة جتا ٤٥° (مقرّبة إلى منزلتين) ؟', 'answer' => '0.71', 'page' => 118,
                                'hint' => 'جتا ٤٥ = √٢/٢ ≈ ٠٫٧٠٧.',
                                'steps' => ['جتا ٤٥ = √٢ ÷ ٢.', '≈ ٠٫٧١.']],
                            ['type' => 'short_answer', 'q' => 'اكتب علاقة إحداثي السينات بعد الدوران.', 'answer' => 'س = سَ جتا هـ − صَ جا هـ', 'page' => 116,
                                'hint' => 'علاقات الدوران تربط الإحداثيات القديمة بالجديدة.',
                                'steps' => ['س = سَ جتا هـ − صَ جا هـ.']],
                        ],
                    ],
                ],
            ],

            [
                'title' => 'الوحدة الخامسة: الهندسة الفضائية',
                'lessons' => [
                    [
                        'title' => 'المستقيمات والمستويات في الفضاء',
                        'from' => 121, 'to' => 128,
                        'keywords' => ['الفضاء', 'المستويات', 'المستقيمات'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'المستقيمات والمستويات في الفضاء', 'page' => 121],
                            ['type' => 'definition', 'content' => 'المستوى في الفضاء سطح مستوٍ لا نهائي الامتداد، ويُحدَّد بثلاث نقاط غير على استقامة واحدة.', 'page' => 122],
                            ['type' => 'point', 'content' => 'الوضع النسبي لمستقيم ومستوى: أن يقع المستقيم في المستوى، أو يوازيه، أو يقطعه في نقطة.', 'page' => 124],
                            ['type' => 'point', 'content' => 'المستقيمان المتخالفان لا يتقاطعان وليسا متوازيين (لا يقعان في مستوى واحد).', 'page' => 125],
                            ['type' => 'note', 'content' => 'المستقيم العمودي على مستويين متوازيين يكون عموديًا على كليهما.', 'page' => 127],
                        ],
                        'exercises' => [
                            ['type' => 'mcq', 'q' => 'كم نقطة (غير على استقامة واحدة) تحدّد مستوى واحدًا ؟', 'answer' => 'ثلاث', 'page' => 122,
                                'options' => [['text' => 'ثلاث', 'correct' => true], ['text' => 'اثنتان'], ['text' => 'أربع']],
                                'hint' => 'المستوى يُحدَّد بثلاث نقاط غير مستقيمية.',
                                'steps' => ['ثلاث نقاط غير على استقامة واحدة تحدّد مستوى واحدًا.']],
                            ['type' => 'mcq', 'q' => 'المستقيمان المتخالفان:', 'answer' => 'لا يتقاطعان وليسا متوازيين', 'page' => 125,
                                'options' => [['text' => 'لا يتقاطعان وليسا متوازيين', 'correct' => true], ['text' => 'يتقاطعان'], ['text' => 'متوازيان دائمًا']],
                                'hint' => 'راجع تعريف المستقيمين المتخالفين.',
                                'steps' => ['المتخالفان لا يقعان في مستوى واحد.', 'إذن لا يتقاطعان وليسا متوازيين.']],
                            ['type' => 'short_answer', 'q' => 'اذكر الأوضاع النسبية الممكنة بين مستقيم ومستوى.', 'answer' => 'يقع فيه أو يوازيه أو يقطعه', 'page' => 124,
                                'hint' => 'ثلاث حالات ممكنة.',
                                'steps' => ['المستقيم يقع في المستوى، أو يوازيه، أو يقطعه في نقطة.']],
                        ],
                    ],
                    [
                        'title' => 'المتجهات في الفضاء',
                        'from' => 129, 'to' => 136,
                        'keywords' => ['المتجهات', 'الفضاء', 'الضرب القياسي'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'المتجهات في الفضاء', 'page' => 129],
                            ['type' => 'definition', 'content' => 'المتجه في الفضاء يُكتب بمركباته الثلاث: أ = (أ₁، أ₂، أ₃)، ومقداره |أ| = √(أ₁² + أ₂² + أ₃²).', 'page' => 130],
                            ['type' => 'definition', 'content' => 'الضرب القياسي لمتجهين: أ · ب = أ₁ب₁ + أ₂ب₂ + أ₃ب₃، ويكون صفرًا إذا كان المتجهان متعامدين.', 'page' => 132],
                            ['type' => 'example', 'content' => 'مثال: أ = (١، ٢، ٢) فمقداره |أ| = √(١ + ٤ + ٤) = √٩ = ٣.', 'page' => 131],
                            ['type' => 'note', 'content' => 'المتجهان متعامدان إذا وفقط إذا كان حاصل ضربهما القياسي صفرًا.', 'page' => 134],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'ما مقدار المتجه أ = (٢، ٣، ٦) ؟', 'answer' => '7', 'page' => 131,
                                'hint' => '|أ| = √(٢² + ٣² + ٦²).',
                                'steps' => ['|أ| = √(٤ + ٩ + ٣٦).', '= √٤٩ = ٧.']],
                            ['type' => 'numeric', 'q' => 'ما حاصل الضرب القياسي للمتجهين (١، ٢، ٣) و(٤، ٥، −٤) ؟', 'answer' => '2', 'page' => 132,
                                'hint' => 'اضرب المركبات المتناظرة واجمعها.',
                                'steps' => ['١×٤ + ٢×٥ + ٣×(−٤).', '= ٤ + ١٠ − ١٢.', '= ٢.']],
                            ['type' => 'mcq', 'q' => 'يكون المتجهان متعامدين إذا كان حاصل ضربهما القياسي:', 'answer' => 'صفرًا', 'page' => 134,
                                'options' => [['text' => 'صفرًا', 'correct' => true], ['text' => 'واحدًا'], ['text' => 'موجبًا']],
                                'hint' => 'التعامد مرتبط بالضرب القياسي.',
                                'steps' => ['شرط التعامد: أ · ب = ٠.']],
                        ],
                    ],
                    [
                        'title' => 'الحجوم والمساحات في الفضاء',
                        'from' => 137, 'to' => 144,
                        'keywords' => ['الحجوم', 'المساحات', 'الفضاء'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'الحجوم والمساحات', 'page' => 137],
                            ['type' => 'definition', 'content' => 'حجم متوازي المستطيلات = الطول × العرض × الارتفاع.', 'page' => 138],
                            ['type' => 'definition', 'content' => 'حجم الكرة = (٤/٣) × ط × نق³، ومساحة سطحها = ٤ ط نق².', 'page' => 140],
                            ['type' => 'point', 'content' => 'حجم الأسطوانة = ط نق² × ع، ومساحتها الجانبية = ٢ ط نق ع.', 'page' => 141],
                            ['type' => 'example', 'content' => 'مثال: كرة نصف قطرها ٣ سم؛ حجمها = (٤/٣) ط (٢٧) = ٣٦ ط سم³.', 'page' => 142],
                            ['type' => 'note', 'content' => 'حجم المخروط = (١/٣) ط نق² ع، أي ثلث حجم الأسطوانة المكافئة.', 'page' => 143],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'ما مساحة سطح كرة نصف قطرها ٢ سم ؟ (اكتب 16π كرقم 16)', 'answer' => '16', 'page' => 140,
                                'hint' => 'مساحة سطح الكرة = ٤ ط نق².',
                                'steps' => ['مساحة السطح = ٤ ط (٢)².', '= ٤ ط × ٤ = ١٦ ط.', 'المعامل الرقمي ١٦.']],
                            ['type' => 'numeric', 'q' => 'ما حجم متوازي مستطيلات أبعاده ٢ × ٣ × ٤ ؟', 'answer' => '24', 'page' => 138,
                                'hint' => 'الحجم = الطول × العرض × الارتفاع.',
                                'steps' => ['٢ × ٣ × ٤.', '= ٢٤ وحدة مكعبة.']],
                            ['type' => 'short_answer', 'q' => 'اكتب قانون حجم الكرة.', 'answer' => '(٤/٣) ط نق³', 'page' => 140,
                                'hint' => 'يبدأ بالكسر ٤/٣.',
                                'steps' => ['حجم الكرة = (٤/٣) ط نق³.']],
                        ],
                    ],
                ],
            ],

            [
                'title' => 'الوحدة السادسة: التفاضل',
                'lessons' => [
                    [
                        'title' => 'نهايات واتصال الدوال',
                        'from' => 145, 'to' => 152,
                        'keywords' => ['النهايات', 'الاتصال'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'نهايات واتصال الدوال', 'page' => 145],
                            ['type' => 'definition', 'content' => 'نهاية الدالة عند نقطة: القيمة التي تقترب إليها الدالة عندما يقترب المتغير من تلك النقطة.', 'page' => 146],
                            ['type' => 'point', 'content' => 'تكون الدالة متصلة عند نقطة إذا كانت النهاية موجودة وتساوي قيمة الدالة عند تلك النقطة.', 'page' => 148],
                            ['type' => 'example', 'content' => 'مثال: نهاية (س² − ١)/(س − ١) عندما س → ١ = نهاية (س + ١) = ٢.', 'page' => 150],
                            ['type' => 'note', 'content' => 'قد تكون النهاية موجودة حتى إذا لم تكن الدالة معرّفة عند النقطة نفسها.', 'page' => 151],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'ما نهاية الدالة (س + ٣) عندما س → ٢ ؟', 'answer' => '5', 'page' => 146,
                                'hint' => 'عوّض مباشرة س = ٢.',
                                'steps' => ['نعوّض س = ٢.', '٢ + ٣ = ٥.']],
                            ['type' => 'mcq', 'q' => 'تكون الدالة متصلة عند نقطة إذا:', 'answer' => 'النهاية تساوي قيمة الدالة', 'page' => 148,
                                'options' => [['text' => 'النهاية تساوي قيمة الدالة', 'correct' => true], ['text' => 'النهاية صفر'], ['text' => 'الدالة متزايدة']],
                                'hint' => 'اربط النهاية بقيمة الدالة.',
                                'steps' => ['شرط الاتصال: النهاية = قيمة الدالة عند النقطة.']],
                            ['type' => 'short_answer', 'q' => 'ما نهاية (س² − ٤)/(س − ٢) عندما س → ٢ ؟', 'answer' => '4', 'page' => 150,
                                'hint' => 'حلّل البسط: س² − ٤ = (س − ٢)(س + ٢).',
                                'steps' => ['س² − ٤ = (س − ٢)(س + ٢).', 'نختصر (س − ٢).', 'النهاية = ٢ + ٢ = ٤.']],
                        ],
                    ],
                    [
                        'title' => 'مشتقة الدالة اللوغاريتمية والأسية',
                        'from' => 153, 'to' => 160,
                        'keywords' => ['المشتقة', 'اللوغاريتم', 'الأسي'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'مشتقة الدوال اللوغاريتمية والأسية', 'page' => 153],
                            ['type' => 'definition', 'content' => 'مشتقة الدالة اللوغاريتمية: إذا كان ص = لو س فإن صَ = ١/س.', 'page' => 154],
                            ['type' => 'definition', 'content' => 'مشتقة الدالة الأسية: إذا كان ص = هـ^س فإن صَ = هـ^س، وإذا كان ص = أ^س فإن صَ = أ^س لو أ.', 'page' => 156],
                            ['type' => 'point', 'content' => 'مشتقة لو(د) حيث د دالة في س هي: صَ = دَ/د (قاعدة السلسلة).', 'page' => 157],
                            ['type' => 'example', 'content' => 'مثال: ص = لو(٣س + ١)، صَ = ٣/(٣س + ١).', 'page' => 158],
                            ['type' => 'note', 'content' => 'مشتقة هـ^س هي نفسها هـ^س؛ هذه خاصية تفرّد الدالة الأسية الطبيعية.', 'page' => 159],
                        ],
                        'exercises' => [
                            ['type' => 'mcq', 'q' => 'ما مشتقة ص = لو س ؟', 'answer' => '١/س', 'page' => 154,
                                'options' => [['text' => '١/س', 'correct' => true], ['text' => 'س'], ['text' => 'لو س']],
                                'hint' => 'مشتقة اللوغاريتم الطبيعي.',
                                'steps' => ['ص = لو س → صَ = ١/س.']],
                            ['type' => 'mcq', 'q' => 'ما مشتقة ص = هـ^س ؟', 'answer' => 'هـ^س', 'page' => 156,
                                'options' => [['text' => 'هـ^س', 'correct' => true], ['text' => 'س هـ^(س−١)'], ['text' => '١']],
                                'hint' => 'الدالة الأسية الطبيعية تساوي مشتقتها.',
                                'steps' => ['ص = هـ^س → صَ = هـ^س.']],
                            ['type' => 'short_answer', 'q' => 'ما مشتقة ص = لو(٢س) ؟', 'answer' => '٢/٢س أو ١/س', 'page' => 158,
                                'hint' => 'استخدم قاعدة السلسلة: دَ/د.',
                                'steps' => ['ص = لو(٢س).', 'صَ = (٢)/(٢س).', '= ١/س.']],
                        ],
                    ],
                    [
                        'title' => 'مشتقة الدوال المثلثية',
                        'from' => 161, 'to' => 168,
                        'keywords' => ['المشتقة', 'المثلثية', 'جا', 'جتا'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'مشتقة الدوال المثلثية', 'page' => 161],
                            ['type' => 'definition', 'content' => 'مشتقة الجيب: إذا كان ص = جا س فإن صَ = جتا س.', 'page' => 162],
                            ['type' => 'definition', 'content' => 'مشتقة جيب التمام: إذا كان ص = جتا س فإن صَ = − جا س.', 'page' => 163],
                            ['type' => 'definition', 'content' => 'مشتقة الظل: إذا كان ص = ظا س فإن صَ = قا² س.', 'page' => 164],
                            ['type' => 'example', 'content' => 'مثال: ص = جا(٣س)، صَ = ٣ جتا(٣س) بقاعدة السلسلة.', 'page' => 166],
                            ['type' => 'note', 'content' => 'لا تنسَ الضرب في مشتقة الزاوية الداخلية (قاعدة السلسلة).', 'page' => 167],
                        ],
                        'exercises' => [
                            ['type' => 'mcq', 'q' => 'ما مشتقة ص = جا س ؟', 'answer' => 'جتا س', 'page' => 162,
                                'options' => [['text' => 'جتا س', 'correct' => true], ['text' => '− جتا س'], ['text' => 'جا س']],
                                'hint' => 'مشتقة الجيب هي جيب التمام.',
                                'steps' => ['ص = جا س → صَ = جتا س.']],
                            ['type' => 'mcq', 'q' => 'ما مشتقة ص = جتا س ؟', 'answer' => '− جا س', 'page' => 163,
                                'options' => [['text' => '− جا س', 'correct' => true], ['text' => 'جا س'], ['text' => 'جتا س']],
                                'hint' => 'لاحظ الإشارة السالبة.',
                                'steps' => ['ص = جتا س → صَ = − جا س.']],
                            ['type' => 'short_answer', 'q' => 'ما مشتقة ص = جا(٢س) ؟', 'answer' => '٢ جتا(٢س)', 'page' => 166,
                                'hint' => 'استخدم قاعدة السلسلة.',
                                'steps' => ['ص = جا(٢س).', 'صَ = جتا(٢س) × ٢.', '= ٢ جتا(٢س).']],
                        ],
                    ],
                    [
                        'title' => 'دراسة تغيّر الدالة',
                        'from' => 169, 'to' => 176,
                        'keywords' => ['تغيّر الدالة', 'القيم العظمى والصغرى'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'دراسة تغيّر الدالة', 'page' => 169],
                            ['type' => 'definition', 'content' => 'تكون الدالة متزايدة إذا كانت مشتقتها موجبة، ومتناقصة إذا كانت مشتقتها سالبة.', 'page' => 170],
                            ['type' => 'definition', 'content' => 'النقطة الحرجة: النقطة التي عندها صَ = ٠ أو صَ غير معرّفة.', 'page' => 172],
                            ['type' => 'point', 'content' => 'القيمة العظمى المحلية تحدث عندما تتغيّر إشارة المشتقة من موجب إلى سالب، والصغرى بالعكس.', 'page' => 173],
                            ['type' => 'example', 'content' => 'مثال: ص = س² − ٤س؛ صَ = ٢س − ٤ = ٠ → س = ٢ نقطة حرجة، وهي قيمة صغرى.', 'page' => 174],
                            ['type' => 'note', 'content' => 'اختبار المشتقة الثانية: إذا كانت صََ > ٠ فالنقطة صغرى، وإذا كانت صََ < ٠ فالنقطة عظمى.', 'page' => 175],
                        ],
                        'exercises' => [
                            ['type' => 'mcq', 'q' => 'تكون الدالة متزايدة عندما تكون مشتقتها:', 'answer' => 'موجبة', 'page' => 170,
                                'options' => [['text' => 'موجبة', 'correct' => true], ['text' => 'سالبة'], ['text' => 'صفرًا']],
                                'hint' => 'اربط إشارة المشتقة بالسلوك.',
                                'steps' => ['مشتقة موجبة → الدالة متزايدة.']],
                            ['type' => 'numeric', 'q' => 'ما قيمة س عند النقطة الحرجة للدالة ص = س² − ٦س ؟', 'answer' => '3', 'page' => 174,
                                'hint' => 'اجعل صَ = ٠: ٢س − ٦ = ٠.',
                                'steps' => ['صَ = ٢س − ٦.', '٢س − ٦ = ٠.', 'س = ٣.']],
                            ['type' => 'mcq', 'q' => 'إذا كانت صََ > ٠ عند نقطة حرجة فهي نقطة:', 'answer' => 'صغرى', 'page' => 175,
                                'options' => [['text' => 'صغرى', 'correct' => true], ['text' => 'عظمى'], ['text' => 'انقلاب']],
                                'hint' => 'اختبار المشتقة الثانية.',
                                'steps' => ['صََ > ٠ → نقطة صغرى محلية.']],
                        ],
                    ],
                ],
            ],

            [
                'title' => 'الوحدة السابعة: التكامل',
                'lessons' => [
                    [
                        'title' => 'تكامل الدوال الكسرية',
                        'from' => 177, 'to' => 184,
                        'keywords' => ['التكامل', 'الدوال الكسرية'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'تكامل الدوال الكسرية', 'page' => 177],
                            ['type' => 'definition', 'content' => 'التكامل عملية عكسية للتفاضل؛ فإذا كانت مشتقة د هي دَ فإن تكامل دَ بالنسبة إلى س هو د + ث.', 'page' => 178],
                            ['type' => 'definition', 'content' => 'تكامل ١/س بالنسبة إلى س هو لو |س| + ث.', 'page' => 180],
                            ['type' => 'point', 'content' => 'ثابت التكامل ث يظهر لأن مشتقة أي ثابت صفر.', 'page' => 181],
                            ['type' => 'example', 'content' => 'مثال: تكامل (٢/س) دس = ٢ لو |س| + ث.', 'page' => 182],
                            ['type' => 'note', 'content' => 'للتكامل الخطية: تكامل (أ د + ب هـ) = أ تكامل د + ب تكامل هـ.', 'page' => 183],
                        ],
                        'exercises' => [
                            ['type' => 'mcq', 'q' => 'ما تكامل ١/س بالنسبة إلى س ؟', 'answer' => 'لو |س| + ث', 'page' => 180,
                                'options' => [['text' => 'لو |س| + ث', 'correct' => true], ['text' => 'س + ث'], ['text' => '−١/س + ث']],
                                'hint' => 'التكامل عكس التفاضل: مشتقة لو س هي ١/س.',
                                'steps' => ['بما أن مشتقة لو |س| هي ١/س.', 'فإن تكامل ١/س = لو |س| + ث.']],
                            ['type' => 'numeric', 'q' => 'ما ناتج تكامل ٣ دس (دون ثابت التكامل) ؟ (اكتب 3x)', 'answer' => '3x', 'page' => 178,
                                'hint' => 'تكامل الثابت ٣ بالنسبة إلى س هو ٣س.',
                                'steps' => ['تكامل ٣ دس = ٣س + ث.', 'دون الثابت: ٣س.']],
                            ['type' => 'short_answer', 'q' => 'اكتب تكامل س² دس.', 'answer' => 'س³/٣ + ث', 'page' => 179,
                                'hint' => 'زد الأس واحدًا واقسم على الأس الجديد.',
                                'steps' => ['تكامل س² دس = س³/٣ + ث.']],
                        ],
                    ],
                    [
                        'title' => 'حساب مساحات المناطق المستوية',
                        'from' => 185, 'to' => 192,
                        'keywords' => ['المساحات', 'التكامل المحدود'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'حساب مساحات المناطق المستوية', 'page' => 185],
                            ['type' => 'definition', 'content' => 'التكامل المحدود من أ إلى ب لدالة موجبة يساوي مساحة المنطقة المحصورة بين المنحنى ومحور السينات والمستقيمين س = أ، س = ب.', 'page' => 186],
                            ['type' => 'point', 'content' => 'يُحسب التكامل المحدود بإيجاد التكامل غير المحدود ثم التعويض بالحدين والطرح: [د(ب) − د(أ)].', 'page' => 188],
                            ['type' => 'example', 'content' => 'مثال: مساحة تحت ص = س من ٠ إلى ٢ = [س²/٢] من ٠ إلى ٢ = ٢ − ٠ = ٢ وحدة مربعة.', 'page' => 189],
                            ['type' => 'note', 'content' => 'إذا كان المنحنى أسفل محور السينات تكون المساحة سالبة، فنأخذ القيمة المطلقة.', 'page' => 191],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'ما مساحة المنطقة تحت المنحنى ص = س من س = ٠ إلى س = ٣ ؟', 'answer' => '4.5', 'page' => 189,
                                'hint' => 'احسب [س²/٢] من ٠ إلى ٣.',
                                'steps' => ['التكامل = س²/٢.', 'عند ٣: ٩/٢ = ٤٫٥.', 'عند ٠: ٠.', 'المساحة = ٤٫٥.']],
                            ['type' => 'mcq', 'q' => 'يُحسب التكامل المحدود بـ:', 'answer' => 'التعويض بالحدين والطرح', 'page' => 188,
                                'options' => [['text' => 'التعويض بالحدين والطرح', 'correct' => true], ['text' => 'الجمع فقط'], ['text' => 'الضرب في الحدين']],
                                'hint' => 'القاعدة الأساسية للتكامل المحدود.',
                                'steps' => ['التكامل المحدود = د(الحد الأعلى) − د(الحد الأدنى).']],
                            ['type' => 'short_answer', 'q' => 'اكتب الصيغة الرياضية للمساحة بالتكامل المحدود.', 'answer' => 'المساحة = تكامل من أ إلى ب لـ د(س) دس', 'page' => 186,
                                'hint' => 'اربط المساحة بالتكامل بين حدّين.',
                                'steps' => ['المساحة = ∫ من أ إلى ب د(س) دس.']],
                        ],
                    ],
                    [
                        'title' => 'الحجوم الدورانية',
                        'from' => 193, 'to' => 200,
                        'keywords' => ['الحجوم الدورانية', 'التكامل'],
                        'sections' => [
                            ['type' => 'heading', 'content' => 'الحجوم الدورانية', 'page' => 193],
                            ['type' => 'definition', 'content' => 'الحجم الدوراني: حجم الجسم الناتج عن دوران منطقة حول محور، ويُحسب بـ ح = ط ∫ من أ إلى ب (د(س))² دس.', 'page' => 194],
                            ['type' => 'point', 'content' => 'نربّع الدالة قبل التكامل لأن كل مقطع عمودي على المحور قرص دائري نصف قطره د(س).', 'page' => 196],
                            ['type' => 'example', 'content' => 'مثال: دوران ص = س حول محور السينات من ٠ إلى ١ يعطي ح = ط ∫ س² دس = ط [س³/٣] من ٠ إلى ١ = ط/٣.', 'page' => 197],
                            ['type' => 'note', 'content' => 'الحجم يُقاس بوحدات مكعبة، والمساحة بوحدات مربعة.', 'page' => 199],
                        ],
                        'exercises' => [
                            ['type' => 'numeric', 'q' => 'دوران ص = س حول محور السينات من ٠ إلى ١: ما معامل ط في الحجم ؟ (اكتب 0.333)', 'answer' => '0.333', 'page' => 197,
                                'hint' => 'ح = ط [س³/٣] من ٠ إلى ١.',
                                'steps' => ['ح = ط ∫ س² دس من ٠ إلى ١.', '= ط [س³/٣] من ٠ إلى ١.', '= ط/٣ ≈ ٠٫٣٣٣ ط.']],
                            ['type' => 'mcq', 'q' => 'في حساب الحجم الدوراني نربّع الدالة لأن المقطع:', 'answer' => 'قرص دائري', 'page' => 196,
                                'options' => [['text' => 'قرص دائري', 'correct' => true], ['text' => 'مربع'], ['text' => 'مثلث']],
                                'hint' => 'مساحة الدائرة تعتمد على مربع نصف القطر.',
                                'steps' => ['المقطع العمودي قرص دائري نصف قطره د(س).', 'مساحته = ط (د(س))².']],
                            ['type' => 'short_answer', 'q' => 'اكتب قانون الحجم الدوراني حول محور السينات.', 'answer' => 'ح = ط ∫ (د(س))² دس', 'page' => 194,
                                'hint' => 'يبدأ بالثابت ط.',
                                'steps' => ['ح = ط ∫ من أ إلى ب (د(س))² دس.']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
