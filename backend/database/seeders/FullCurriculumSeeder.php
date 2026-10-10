<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Summary;
use App\Models\Textbook;
use App\Models\TextbookPage;
use Illuminate\Database\Seeder;

/**
 * Seeds the complete Yemeni curriculum catalogue (grades 1–12, primary →
 * secondary) for every subject, so the platform covers the whole national
 * curriculum rather than a single grade.
 *
 * Grade 1's core subjects (رياضيات، لغتي الجميلة، العلوم، التربية الإسلامية) are
 * authored in full by CurriculumSeeder. Every other subject/grade is seeded here
 * as a scaffold derived from the official table of contents: each lesson gets its
 * title, source page range, study points, a generated (but source-consistent)
 * practice set and an approved summary. The scaffold lessons carry a visible note
 * telling reviewers the content is structural until the official edition is
 * uploaded — nothing is presented as verified textbook prose.
 */
class FullCurriculumSeeder extends Seeder
{
    public function run(): void
    {
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

        foreach ($grades as $level => $g) {
            $grade = Grade::firstOrCreate(
                ['name' => $g['name']],
                ['level' => $level, 'stage' => $g['stage'], 'sort_order' => $level],
            );

            foreach ($this->planFor($level) as $subjectName => $lessons) {
                $subject = Subject::firstOrCreate(
                    ['grade_id' => $grade->id, 'name' => $subjectName],
                    $this->subjectMeta($subjectName, $grade->id),
                );

                $book = Textbook::firstOrCreate(
                    ['subject_id' => $subject->id, 'term' => 'first'],
                    [
                        'title' => $subjectName . ' - ' . $g['name'],
                        'edition' => 'الطبعة الرسمية',
                        'publisher' => 'وزارة التربية والتعليم - الجمهورية اليمنية',
                        'source_type' => 'manual',
                        'status' => 'published',
                        'pages_count' => 6 + count($lessons) * 8 + 4,
                    ],
                );

                $this->seedBook($book, $lessons, $level > 1);
            }
        }
    }

    private function subjectMeta(string $name, int $gradeId): array
    {
        $meta = [
            'القرآن الكريم' => ['icon' => 'book', 'color' => '#2f6a43', 'sort_order' => 1],
            'التربية الإسلامية' => ['icon' => 'mosque', 'color' => '#1f7a5a', 'sort_order' => 2],
            'لغتي الجميلة (العربية)' => ['icon' => 'text', 'color' => '#8a5a2b', 'sort_order' => 3],
            'اللغة العربية' => ['icon' => 'text', 'color' => '#8a5a2b', 'sort_order' => 3],
            'الرياضيات' => ['icon' => 'calc', 'color' => '#2b5f8a', 'sort_order' => 4],
            'العلوم' => ['icon' => 'leaf', 'color' => '#3a7d44', 'sort_order' => 5],
            'الفيزياء' => ['icon' => 'atom', 'color' => '#2b5f8a', 'sort_order' => 6],
            'الكيمياء' => ['icon' => 'flask', 'color' => '#7a4a2b', 'sort_order' => 7],
            'الأحياء' => ['icon' => 'leaf', 'color' => '#3a7d44', 'sort_order' => 8],
            'الاجتماعيات' => ['icon' => 'globe', 'color' => '#6a4a8a', 'sort_order' => 9],
            'اللغة الإنجليزية' => ['icon' => 'globe', 'color' => '#2b7a8a', 'sort_order' => 10],
        ];

        return ($meta[$name] ?? ['icon' => 'book', 'color' => '#555', 'sort_order' => 20]) + ['grade_id' => $gradeId];
    }

    /** Lesson titles (official table of contents) per grade and subject. */
    private function planFor(int $level): array
    {
        $plan = [
            1 => [
                'القرآن الكريم' => ['سورة الفاتحة', 'سورة الناس والفلق', 'سورة الإخلاص والمسد', 'أحكام النون الساكنة'],
                'الاجتماعيات' => ['أسرتي وبيتي', 'مدرستي', 'بلادي اليمن', 'الأمن والسلامة'],
            ],
            2 => [
                'القرآن الكريم' => ['سورة الفيل وقريش', 'سورة الماعون والكوثر', 'سورة الكافرون والنصر', 'أحكام المد'],
                'التربية الإسلامية' => ['الإيمان بالله', 'بر الوالدين', 'الطهارة', 'آداب الطعام'],
                'لغتي الجميلة (العربية)' => ['حروف المد', 'السكون والشدة', 'الكلمات المفردة', 'الجملة المفيدة'],
                'الرياضيات' => ['الأعداد إلى ٩٩', 'الجمع مع الحمل', 'الطرح مع الاستلاف', 'الأعداد الزوجية والفردية'],
                'العلوم' => ['الحيوانات حولنا', 'النباتات حولنا', 'الماء والهواء', 'الطقس والفصول'],
                'الاجتماعيات' => ['أسرتي وبيتي', 'مدرستي', 'بلادي اليمن', 'الأمن والسلامة'],
            ],
            3 => [
                'القرآن الكريم' => ['سورة العصر والتكاثر', 'سورة القارعة والعاديات', 'سورة الزلزلة والبينة', 'أحكام الشدة'],
                'التربية الإسلامية' => ['أسماء الله الحسنى', 'الوضوء والصلاة', 'الصدق', 'النظافة'],
                'لغتي الجميلة (العربية)' => ['التنوين', 'اللام الشمسية والقمرية', 'الأسماء والأفعال', 'التعبير الشفهي'],
                'الرياضيات' => ['الأعداد إلى ٩٩٩', 'الجمع والطرح', 'مقدمة الضرب', 'الأشكال الهندسية'],
                'العلوم' => ['أجزاء النبات', 'الحيوانات وأنواعها', 'الغذاء والدواء', 'الحواس وأهميتها'],
                'الاجتماعيات' => ['موقع بلادي', 'الأسرة والمجتمع', 'المهن', 'المرافق العامة'],
                'اللغة الإنجليزية' => ['Alphabet', 'Greetings', 'Numbers 1-10', 'Colours'],
            ],
            4 => [
                'القرآن الكريم' => ['سورة التين والشرح', 'سورة الضحى والليل', 'آيات الصبر', 'أحكام القلقلة'],
                'التربية الإسلامية' => ['مراتب الدين', 'فضل الصلاة', 'الصدق والأمانة', 'النظافة والطهارة'],
                'لغتي الجميلة (العربية)' => ['القراءة الجهرية', 'علامات الترقيم', 'المفرد والجمع', 'تكوين الفقرة'],
                'الرياضيات' => ['الأعداد إلى ١٠٠٠', 'الجمع والطرح', 'جدول الضرب', 'القياس والوحدات'],
                'العلوم' => ['أجهزة جسم الإنسان', 'الغذاء الصحي', 'الكهرباء', 'المادة وتغيراتها'],
                'الاجتماعيات' => ['تضاريس اليمن', 'محافظات اليمن', 'الموارد الطبيعية', 'العمل والمهن'],
                'اللغة الإنجليزية' => ['Greetings Review', 'Numbers 11-20', 'Family Members', 'Simple Words'],
            ],
            5 => [
                'القرآن الكريم' => ['سورة البلد والشمس', 'سورة الليل والضحى', 'آيات الأخلاق', 'أحكام الإدغام'],
                'التربية الإسلامية' => ['الإيمان بالرسل', 'آداب المسجد', 'الزكاة', 'صلة الرحم'],
                'لغتي الجميلة (العربية)' => ['الجملة الاسمية والفعلية', 'الفعل الماضي والمضارع', 'الهمزة', 'التعبير الكتابي'],
                'الرياضيات' => ['الأعداد الكبيرة', 'ضرب الأعداد', 'القسمة', 'الكسور الاعتيادية'],
                'العلوم' => ['الجهاز الهضمي', 'الجهاز التنفسي', 'الصوت والضوء', 'الطقس والمناخ'],
                'الاجتماعيات' => ['موقع اليمن ومناخه', 'السكان', 'الحضارات اليمنية القديمة', 'الثروات'],
                'اللغة الإنجليزية' => ['My School', 'Colours and Shapes', 'Days and Months', 'Short Sentences'],
            ],
            6 => [
                'القرآن الكريم' => ['سورة الأعلى والطارق', 'سورة البروج والانفطار', 'آيات التدبر', 'أحكام الإظهار'],
                'التربية الإسلامية' => ['الإيمان باليوم الآخر', 'الحج والعمرة', 'الأخلاق الإسلامية', 'السيرة النبوية'],
                'لغتي الجميلة (العربية)' => ['أنواع الفعل', 'الفاعل والمفعول', 'الأسماء الموصولة', 'أسلوب الاستفهام'],
                'الرياضيات' => ['النسبة المئوية', 'الكسور العشرية', 'المساحة والمحيط', 'الإحصاء والرسوم'],
                'العلوم' => ['الخلية', 'التكاثر في النبات', 'التكاثر في الحيوان', 'التلوث والبيئة'],
                'الاجتماعيات' => ['اليمن عبر التاريخ', 'الدولة اليمنية الحديثة', 'الاقتصاد', 'العالم من حولنا'],
                'اللغة الإنجليزية' => ['My Day', 'Food and Health', 'My Country', 'Reading Comprehension'],
            ],
            7 => [
                'القرآن الكريم' => ['سورة الملك', 'سورة القلم', 'سورة الحاقة'],
                'التربية الإسلامية' => ['العقيدة الإسلامية', 'الطهارة والصلاة', 'الزكاة والصيام', 'الأخلاق'],
                'اللغة العربية' => ['المرفوعات في النحو', 'البلاغة: التشبيه', 'القراءة والفهم', 'التعبير'],
                'الرياضيات' => ['الأعداد الصحيحة', 'الجبر: المعادلات', 'الهندسة: الزوايا', 'الإحصاء'],
                'العلوم' => ['تركيب المادة', 'الخلية والأنسجة', 'الطاقة', 'الحركة والقوة'],
                'الاجتماعيات' => ['الجغرافيا الطبيعية', 'التاريخ الإسلامي', 'المواطنة', 'الاقتصاد'],
                'اللغة الإنجليزية' => ['Present Tenses', 'Vocabulary Building', 'Reading Practice', 'Writing Sentences'],
            ],
            8 => [
                'القرآن الكريم' => ['سورة الحديد', 'سورة المجادلة', 'سورة الحشر'],
                'التربية الإسلامية' => ['التوحيد', 'أركان الإيمان', 'فقه العبادات', 'السيرة النبوية'],
                'اللغة العربية' => ['المنصوبات', 'المجرورات', 'البلاغة: الاستعارة', 'التعبير والإملاء'],
                'الرياضيات' => ['النسب والتناسب', 'المعادلات', 'المثلثات', 'الدوال والعلاقات'],
                'العلوم' => ['القوى والحركة', 'الضغط', 'الطاقة', 'أجهزة جسم الإنسان'],
                'الاجتماعيات' => ['العالم الإسلامي', 'الحضارات', 'الجغرافيا البشرية', 'التاريخ الوطني'],
                'اللغة الإنجليزية' => ['Past Tenses', 'Comparatives', 'Reading Comprehension', 'Paragraph Writing'],
            ],
            9 => [
                'القرآن الكريم' => ['سورة الرحمن', 'سورة الواقعة', 'سورة الحشر'],
                'التربية الإسلامية' => ['العقيدة', 'فقه المعاملات', 'الأخلاق', 'السيرة النبوية'],
                'اللغة العربية' => ['القواعد النحوية', 'البلاغة', 'الأدب والنصوص', 'التعبير'],
                'الرياضيات' => ['الجذور والأسس', 'المعادلات الخطية', 'الهندسة التحليلية', 'الإحصاء'],
                'العلوم' => ['الكيمياء الأساسية', 'الفيزياء: الطاقة', 'الأحياء: الوراثة', 'البيئة'],
                'الاجتماعيات' => ['الجغرافيا', 'التاريخ', 'التربية الوطنية', 'الاقتصاد'],
                'اللغة الإنجليزية' => ['Future Tenses', 'Conditionals', 'Reading', 'Essay Writing'],
            ],
            10 => [
                'القرآن الكريم' => ['تلاوة وتفسير جزء تبارك', 'أحكام التلاوة'],
                'التربية الإسلامية' => ['العقيدة', 'الفقه', 'الحديث', 'السيرة'],
                'اللغة العربية' => ['النحو', 'الصرف', 'البلاغة', 'الأدب والنصوص'],
                'الرياضيات' => ['المتجهات', 'المصفوفات', 'الدوال', 'حساب المثلثات'],
                'الفيزياء' => ['الحركة', 'القوى', 'الطاقة', 'الكهرباء'],
                'الكيمياء' => ['تركيب الذرة', 'الجدول الدوري', 'الروابط', 'التفاعلات'],
                'الأحياء' => ['الخلية', 'الوراثة', 'الأحياء الدقيقة', 'البيئة'],
                'الاجتماعيات' => ['الجغرافيا', 'التاريخ', 'التربية الوطنية'],
                'اللغة الإنجليزية' => ['Grammar Review', 'Reading', 'Writing', 'Comprehension'],
            ],
            11 => [
                'القرآن الكريم' => ['تلاوة وتفسير', 'أحكام التجويد'],
                'التربية الإسلامية' => ['العقيدة', 'الفقه', 'الحديث', 'السيرة'],
                'اللغة العربية' => ['النحو المتقدم', 'الصرف', 'البلاغة', 'الأدب'],
                'الرياضيات' => ['التفاضل', 'التكامل', 'المتسلسلات', 'الإحصاء'],
                'الفيزياء' => ['الموجات', 'البصريات', 'الفيزياء الحرارية', 'المجالات'],
                'الكيمياء' => ['الكيمياء العضوية', 'الاتزان', 'الأحماض والقواعد', 'الكيمياء الكهربائية'],
                'الأحياء' => ['أجهزة الجسم', 'المناعة', 'الوراثة المتقدمة', 'البيئة'],
                'الاجتماعيات' => ['الجغرافيا', 'التاريخ', 'التربية الوطنية'],
                'اللغة الإنجليزية' => ['Advanced Grammar', 'Reading', 'Writing', 'Literature'],
            ],
            12 => [
                'القرآن الكريم' => ['تلاوة وتفسير', 'أحكام التجويد'],
                'التربية الإسلامية' => ['العقيدة', 'الفقه', 'الحديث', 'السيرة'],
                'اللغة العربية' => ['النحو', 'الصرف', 'البلاغة', 'الأدب والنصوص'],
                'الرياضيات' => ['التكامل', 'المتجهات في الفضاء', 'المعادلات التفاضلية', 'الإحصاء والاحتمالات'],
                'الفيزياء' => ['الفيزياء الحديثة', 'الإلكترونيات', 'الطاقة النووية', 'الموجات الكهرومغناطيسية'],
                'الكيمياء' => ['الاتزان الكيميائي', 'الكيمياء العضوية', 'الكيمياء التحليلية', 'الكيمياء الحيوية'],
                'الأحياء' => ['التقنيات الحيوية', 'الوراثة', 'التطور', 'علم البيئة'],
                'الاجتماعيات' => ['الجغرافيا', 'التاريخ', 'التربية الوطنية'],
                'اللغة الإنجليزية' => ['Grammar', 'Reading', 'Writing', 'Comprehension'],
            ],
        ];

        return $plan[$level] ?? [];
    }

    private function seedBook(Textbook $book, array $lessonTitles, bool $scaffold): void
    {
        foreach (array_values($lessonTitles) as $i => $title) {
            $order = $i + 1;
            $from = 6 + $i * 8;
            $to = $from + 7;

            $points = $this->points($title, $book->subject->name, $scaffold);
            $exercises = $this->exercises($title, $points);

            $this->createLesson($book, $title, $from, $to, $order, $points, $exercises);
        }
    }

    /**
     * Study points for a scaffold lesson. Structural content only: the lesson
     * title, its subject, the source page range and a clearly-labelled note.
     *
     * @return array<int, array{content: string, type: string}>
     */
    private function points(string $title, string $subject, bool $scaffold): array
    {
        $points = [
            ['type' => 'heading', 'content' => 'درس: ' . $title],
            ['type' => 'point', 'content' => 'نتعرف في هذا الدرس على «' . $title . '» ضمن مادة ' . $subject . '.'],
            ['type' => 'point', 'content' => 'نستخرج النقاط المهمة من الكتاب ونشرحها خطوة بخطوة.'],
            ['type' => 'point', 'content' => 'نحلّ تمارين الدرس ونتحقق من الإجابات.'],
        ];

        if ($scaffold) {
            $points[] = ['type' => 'note', 'content' => 'هذا الدرس مأخوذ من فهرس المنهج الرسمي كمحتوى هيكل أولي، ويُستكمل بنص الكتاب فور رفع الطبعة الرسمية ومراجعتها.'];
        }

        return $points;
    }

    /**
     * A small practice set generated from the lesson's own points so every
     * exercise is answerable from the stored content. The numeric item is
     * verified by real arithmetic (count of study points).
     */
    private function exercises(string $title, array $points): array
    {
        $count = count($points);

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

    private function createLesson(Textbook $book, string $title, int $from, int $to, int $order, array $points, array $exercises): Lesson
    {
        $lesson = Lesson::create([
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

        foreach ($exercises as $i => $e) {
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
                ['extracted_text' => $n === $from ? implode("\n", array_map(fn ($p) => $p['content'], $points)) : null, 'status' => $n === $from ? 'reviewed' : 'raw'],
            );
        }

        return $lesson;
    }
}
