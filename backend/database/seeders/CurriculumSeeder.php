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
 * Seeds the real Yemeni Grade-1 (الصف الأول الأساسي) sample curriculum.
 * Content belongs to the Yemeni national curriculum (وزارة التربية والتعليم).
 */
class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $grades = [
            ['name' => 'الصف الأول الأساسي', 'level' => 1, 'stage' => 'primary', 'sort_order' => 1],
            ['name' => 'الصف الثاني الأساسي', 'level' => 2, 'stage' => 'primary', 'sort_order' => 2],
            ['name' => 'الصف الثالث الأساسي', 'level' => 3, 'stage' => 'primary', 'sort_order' => 3],
            ['name' => 'الصف الرابع الأساسي', 'level' => 4, 'stage' => 'primary', 'sort_order' => 4],
            ['name' => 'الصف الخامس الأساسي', 'level' => 5, 'stage' => 'primary', 'sort_order' => 5],
            ['name' => 'الصف السادس الأساسي', 'level' => 6, 'stage' => 'primary', 'sort_order' => 6],
            ['name' => 'الصف السابع الأساسي', 'level' => 7, 'stage' => 'preparatory', 'sort_order' => 7],
            ['name' => 'الصف الثامن الأساسي', 'level' => 8, 'stage' => 'preparatory', 'sort_order' => 8],
            ['name' => 'الصف التاسع الأساسي', 'level' => 9, 'stage' => 'preparatory', 'sort_order' => 9],
            ['name' => 'الصف الأول الثانوي', 'level' => 10, 'stage' => 'secondary', 'sort_order' => 10],
            ['name' => 'الصف الثاني الثانوي', 'level' => 11, 'stage' => 'secondary', 'sort_order' => 11],
            ['name' => 'الصف الثالث الثانوي', 'level' => 12, 'stage' => 'secondary', 'sort_order' => 12],
        ];

        foreach ($grades as $g) {
            Grade::firstOrCreate(['name' => $g['name']], $g);
        }

        $grade1 = Grade::where('level', 1)->first();

        $subjects = [
            ['name' => 'القرآن الكريم', 'icon' => 'book', 'color' => '#2f6a43', 'sort_order' => 1],
            ['name' => 'التربية الإسلامية', 'icon' => 'mosque', 'color' => '#1f7a5a', 'sort_order' => 2],
            ['name' => 'لغتي الجميلة (العربية)', 'icon' => 'text', 'color' => '#8a5a2b', 'sort_order' => 3],
            ['name' => 'الرياضيات', 'icon' => 'calc', 'color' => '#2b5f8a', 'sort_order' => 4],
            ['name' => 'العلوم', 'icon' => 'leaf', 'color' => '#3a7d44', 'sort_order' => 5],
            ['name' => 'الاجتماعيات', 'icon' => 'globe', 'color' => '#6a4a8a', 'sort_order' => 6],
        ];

        foreach ($subjects as $s) {
            Subject::firstOrCreate(
                ['grade_id' => $grade1->id, 'name' => $s['name']],
                $s + ['grade_id' => $grade1->id],
            );
        }

        // ---- الرياضيات ----
        $math = Subject::where('grade_id', $grade1->id)->where('name', 'الرياضيات')->first();
        $mathBook = Textbook::firstOrCreate([
            'subject_id' => $math->id,
            'title' => 'الرياضيات - الصف الأول الأساسي',
            'term' => 'first',
            'edition' => 'الطبعة الرسمية',
            'source_type' => 'manual',
            'status' => 'published',
            'pages_count' => 120,
        ]);
        $this->seedMath($mathBook);

        // ---- لغتي الجميلة ----
        $arabic = Subject::where('grade_id', $grade1->id)->where('name', 'لغتي الجميلة (العربية)')->first();
        $arBook = Textbook::firstOrCreate([
            'subject_id' => $arabic->id,
            'title' => 'لغتي الجميلة - الصف الأول الأساسي',
            'term' => 'first',
            'edition' => 'الطبعة الرسمية',
            'source_type' => 'manual',
            'status' => 'published',
            'pages_count' => 140,
        ]);
        $this->seedArabic($arBook);

        // ---- العلوم ----
        $science = Subject::where('grade_id', $grade1->id)->where('name', 'العلوم')->first();
        $sciBook = Textbook::firstOrCreate([
            'subject_id' => $science->id,
            'title' => 'العلوم - الصف الأول الأساسي',
            'term' => 'first',
            'edition' => 'الطبعة الرسمية',
            'source_type' => 'manual',
            'status' => 'published',
            'pages_count' => 96,
        ]);
        $this->seedScience($sciBook);

        // ---- التربية الإسلامية ----
        $islamic = Subject::where('grade_id', $grade1->id)->where('name', 'التربية الإسلامية')->first();
        $isBook = Textbook::firstOrCreate([
            'subject_id' => $islamic->id,
            'title' => 'التربية الإسلامية - الصف الأول الأساسي',
            'term' => 'first',
            'edition' => 'الطبعة الرسمية',
            'source_type' => 'manual',
            'status' => 'published',
            'pages_count' => 88,
        ]);
        $this->seedIslamic($isBook);
    }

    private function lesson(Textbook $book, string $title, int $from, int $to, int $order, array $sections, array $exercises = []): Lesson
    {
        // Idempotent: re-running the seeder must not duplicate an authored lesson.
        $existing = Lesson::where('textbook_id', $book->id)->where('title', $title)->first();
        if ($existing && $existing->sections()->exists()) {
            return $existing;
        }

        $lesson = Lesson::create([
            'textbook_id' => $book->id,
            'title' => $title,
            'page_from' => $from,
            'page_to' => $to,
            'sort_order' => $order,
            'status' => 'published',
        ]);

        foreach ($sections as $i => $s) {
            $lesson->sections()->create([
                'type' => $s['type'],
                'title' => $s['title'] ?? null,
                'content' => $s['content'],
                'source_page' => $s['page'] ?? $from,
                'sort_order' => $i,
                'is_from_textbook' => true,
            ]);
        }

        $this->ensurePages($book, $from, $to, $sections);

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

        $points = array_values(array_map(fn ($s) => $s['content'], $sections));
        Summary::create([
            'lesson_id' => $lesson->id,
            'title' => 'ملخص: ' . $title,
            'points' => $points,
            'keywords' => [],
            'source_pages' => [$from],
            'generated_by' => 'human',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return $lesson;
    }

    /**
     * Records the textbook source pages for a lesson so each extracted point can
     * be traced back to the page it came from. Pages that carry section content
     * keep that text; the rest are recorded as scanned pages awaiting review.
     */
    private function ensurePages(Textbook $book, int $from, int $to, array $sections): void
    {
        $byPage = [];
        foreach ($sections as $s) {
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

    private function seedMath(Textbook $book): void
    {
        $this->lesson($book, 'الأعداد من ١ إلى ٥', 7, 10, 1, [
            ['type' => 'heading', 'content' => 'نتعلم الأعداد من ١ إلى ٥', 'page' => 7],
            ['type' => 'vocab', 'content' => 'العدد واحد يُكتب هكذا: ١', 'page' => 7],
            ['type' => 'vocab', 'content' => 'العدد اثنان يُكتب هكذا: ٢', 'page' => 7],
            ['type' => 'vocab', 'content' => 'العدد ثلاثة يُكتب هكذا: ٣', 'page' => 8],
            ['type' => 'vocab', 'content' => 'العدد أربعة يُكتب هكذا: ٤', 'page' => 8],
            ['type' => 'vocab', 'content' => 'العدد خمسة يُكتب هكذا: ٥', 'page' => 9],
            ['type' => 'point', 'content' => 'نَعُدّ الأشياء واحدًا واحدًا: تفاحة، تفاحتان، ثلاث تفاحات.', 'page' => 9],
            ['type' => 'note', 'content' => 'العدد يدل على مقدار الأشياء التي نعدّها.', 'page' => 10],
        ], [
            ['type' => 'mcq', 'q' => 'كم عدد النجوم: ★ ★ ★ ؟', 'answer' => 'ثلاثة',
                'options' => [['label' => 'أ', 'text' => 'ثلاثة', 'correct' => true], ['label' => 'ب', 'text' => 'اثنان'], ['label' => 'ج', 'text' => 'خمسة']],
                'hint' => 'عُدّ النجوم واحدًا واحدًا.', 'steps' => ['عُدّ أول نجم.', 'عُدّ النجم الثاني.', 'عُدّ النجم الثالث.', 'المجموع ثلاثة.']],
            ['type' => 'numeric', 'q' => 'اكتب العدد: تفاحة تفاحة (اكتب رقمًا)', 'answer' => '2',
                'hint' => 'العدد بعد الواحد هو اثنان.', 'steps' => ['نعدّ التفاحة الأولى.', 'نعدّ التفاحة الثانية.', 'العدد هو ٢.']],
        ]);

        $this->lesson($book, 'العدّ إلى ١٠', 11, 16, 2, [
            ['type' => 'heading', 'content' => 'نعدّ من ١ إلى ١٠', 'page' => 11],
            ['type' => 'point', 'content' => 'نتسلسل في العدّ: ١، ٢، ٣، ٤، ٥، ٦، ٧، ٨، ٩، ١٠.', 'page' => 12],
            ['type' => 'vocab', 'content' => 'العدد ستة يُكتب ٦.', 'page' => 13],
            ['type' => 'vocab', 'content' => 'العدد سبعة يُكتب ٧.', 'page' => 13],
            ['type' => 'vocab', 'content' => 'العدد ثمانية يُكتب ٨.', 'page' => 14],
            ['type' => 'vocab', 'content' => 'العدد تسعة يُكتب ٩.', 'page' => 15],
            ['type' => 'vocab', 'content' => 'العدد عشرة يُكتب ١٠.', 'page' => 16],
        ], [
            ['type' => 'numeric', 'q' => 'ما العدد الذي يأتي بعد ٩ ؟', 'answer' => '10',
                'steps' => ['نتذكر تسلسل الأعداد.', 'بعد التسعة يأتي العدد عشرة.', 'الجواب: ١٠.'],
                'hint' => 'عُدّ على أصابعك حتى العاشرة.'],
            ['type' => 'mcq', 'q' => 'أي عدد أكبر: ٤ أم ٧ ؟', 'answer' => '7',
                'options' => [['text' => '٤'], ['text' => '٧', 'correct' => true]],
                'hint' => 'العدد الأبعد في العدّ هو الأكبر.', 'steps' => ['نعدّ: ٤ أصغر من ٧.', 'إذن ٧ أكبر.']],
        ]);

        $this->lesson($book, 'الجمع حتى ١٠', 17, 24, 3, [
            ['type' => 'heading', 'content' => 'نتعلم الجمع', 'page' => 17],
            ['type' => 'definition', 'content' => 'الجمع هو ضمّ مجموعتين معًا لمعرفة المجموع الكلي.', 'page' => 18],
            ['type' => 'example', 'content' => 'مثال: ٢ + ٣ = ٥ (تفاحتان + ثلاث تفاحات = خمس تفاحات).', 'page' => 20],
            ['type' => 'point', 'content' => 'علامة الجمع هي (+) وتُقرأ زائد.', 'page' => 19],
            ['type' => 'note', 'content' => 'ترتيب الأعداد لا يغيّر ناتج الجمع: ٢ + ٣ = ٣ + ٢.', 'page' => 22],
        ], [
            ['type' => 'numeric', 'q' => 'احسب: ٣ + ٤ = ؟', 'answer' => '7',
                'steps' => ['نبدأ من العدد ٣.', 'نعدّ أربع خطوات: ٤، ٥، ٦، ٧.', 'الناتج ٧.'],
                'hint' => 'ابدأ من ٣ ثم عُدّ أربعة أرقام.'],
            ['type' => 'numeric', 'q' => 'احسب: ٥ + ٥ = ؟', 'answer' => '10',
                'steps' => ['٥ + ٥ يساوي عشرة.', 'لأن عشرة هي ضعف خمسة.'], 'hint' => 'ضعف العدد خمسة هو عشرة.'],
        ]);

        $this->lesson($book, 'الطرح حتى ١٠', 25, 32, 4, [
            ['type' => 'heading', 'content' => 'نتعلم الطرح', 'page' => 25],
            ['type' => 'definition', 'content' => 'الطرح هو إزالة عدد من مجموعة لمعرفة الباقي.', 'page' => 26],
            ['type' => 'example', 'content' => 'مثال: ٧ - ٢ = ٥ (سبعة أقلام نأخذ منها اثنين فيبقى خمسة).', 'page' => 28],
            ['type' => 'point', 'content' => 'علامة الطرح هي (-) وتُقرأ ناقص.', 'page' => 26],
            ['type' => 'note', 'content' => 'العدد الكبير يأتي أولًا في الطرح.', 'page' => 30],
        ], [
            ['type' => 'numeric', 'q' => 'احسب: ٩ - ٣ = ؟', 'answer' => '6',
                'steps' => ['نبدأ من ٩.', 'نرجع ثلاث خطوات: ٨، ٧، ٦.', 'الباقي ٦.'], 'hint' => 'ارجع من ٩ بمقدار ثلاثة.'],
        ]);
    }

    private function seedArabic(Textbook $book): void
    {
        $this->lesson($book, 'أحرف: أ - ب - ت', 6, 12, 1, [
            ['type' => 'heading', 'content' => 'نتعلم حرف الألف والباء والتاء', 'page' => 6],
            ['type' => 'vocab', 'content' => 'حرف الألف يُكتب: أ، ويأتي في أول كلمة (أَسَد).', 'page' => 7],
            ['type' => 'vocab', 'content' => 'حرف الباء يُكتب: ب، ويأتي في أول كلمة (بَيْت).', 'page' => 9],
            ['type' => 'vocab', 'content' => 'حرف التاء يُكتب: ت، ويأتي في أول كلمة (تُفَّاح).', 'page' => 11],
            ['type' => 'point', 'content' => 'نقرأ الحروف مع الحركات: أَ - أُ - إِ.', 'page' => 12],
        ], [
            ['type' => 'mcq', 'q' => 'ما الحرف الأول في كلمة (بَيْت)؟', 'answer' => 'ب',
                'options' => [['text' => 'أ'], ['text' => 'ب', 'correct' => true], ['text' => 'ت']],
                'hint' => 'انظر إلى أول حرف في الكلمة.', 'steps' => ['كلمة بَيْت تبدأ بحرف الباء.', 'إذن الجواب: ب.']],
        ]);

        $this->lesson($book, 'الحركات: الفتحة والضمة والكسرة', 13, 20, 2, [
            ['type' => 'heading', 'content' => 'نتعلم الحركات', 'page' => 13],
            ['type' => 'definition', 'content' => 'الفتحة شرطة صغيرة فوق الحرف ( ـَ ) وتُقرأ (أَ).', 'page' => 14],
            ['type' => 'definition', 'content' => 'الضمة واو صغيرة فوق الحرف ( ـُ ) وتُقرأ (أُ).', 'page' => 16],
            ['type' => 'definition', 'content' => 'الكسرة شرطة صغيرة تحت الحرف ( ـِ ) وتُقرأ (إِ).', 'page' => 18],
            ['type' => 'point', 'content' => 'نقرأ الكلمات بالحركات: كَتَبَ، كُتُب، كِتَاب.', 'page' => 20],
        ], [
            ['type' => 'mcq', 'q' => 'أي حركة تكون فوق الحرف؟', 'answer' => 'الفتحة',
                'options' => [['text' => 'الفتحة', 'correct' => true], ['text' => 'الكسرة']],
                'hint' => 'الحركة التي فوق الحرف هي الفتحة.', 'steps' => ['الفتحة تُكتب فوق الحرف.', 'الكسرة تُكتب تحته.', 'إذن الجواب: الفتحة.']],
        ]);

        $this->lesson($book, 'المد بالألف', 21, 26, 3, [
            ['type' => 'heading', 'content' => 'نتعلم المد بالألف', 'page' => 21],
            ['type' => 'definition', 'content' => 'المد بالألف: يمدّ الصوت بحرف الألف بعد الفتحة، مثل: بَا، مَا.', 'page' => 22],
            ['type' => 'example', 'content' => 'أمثلة: بَاب، مَاء، نَار.', 'page' => 24],
            ['type' => 'point', 'content' => 'نقرأ المدّ بصوت طويل قليلًا: بااااب.', 'page' => 26],
        ], [
            ['type' => 'short_answer', 'q' => 'اكتب كلمة تبدأ بمد بالألف من الدرس.', 'answer' => 'باب',
                'steps' => ['من أمثلة الدرس: بَاب.', 'تحتوي على مد بالألف.'],
                'hint' => 'انظر إلى أمثلة الدرس: باب، ماء، نار.'],
        ]);
    }

    private function seedScience(Textbook $book): void
    {
        $this->lesson($book, 'الكائنات الحية وغير الحية', 6, 14, 1, [
            ['type' => 'heading', 'content' => 'نفرّق بين الكائنات الحية وغير الحية', 'page' => 6],
            ['type' => 'definition', 'content' => 'الكائن الحي ينمو ويتغذى ويتنفس، مثل: الإنسان والنبات والحيوان.', 'page' => 8],
            ['type' => 'definition', 'content' => 'الجماد لا ينمو ولا يتغذى، مثل: الحجر والكرسي والماء.', 'page' => 10],
            ['type' => 'point', 'content' => 'نلاحظ: القطة كائن حي لأنها تأكل وتنمو.', 'page' => 12],
            ['type' => 'note', 'content' => 'النبات كائن حي لأنه ينمو ويحتاج الماء والضوء.', 'page' => 14],
        ], [
            ['type' => 'mcq', 'q' => 'أي مما يلي كائن حي؟', 'answer' => 'القطة',
                'options' => [['text' => 'الحجر'], ['text' => 'القطة', 'correct' => true], ['text' => 'الكرسي']],
                'hint' => 'الكائن الحي يتغذى وينمو.', 'steps' => ['الحجر لا ينمو، إذن جماد.', 'الكرسي جماد.', 'القطة تأكل وتنمو، إذن كائن حي.']],
        ]);

        $this->lesson($book, 'الحواس الخمس', 15, 24, 2, [
            ['type' => 'heading', 'content' => 'نتعرف على الحواس الخمس', 'page' => 15],
            ['type' => 'vocab', 'content' => 'العين نرى بها الأشياء.', 'page' => 16],
            ['type' => 'vocab', 'content' => 'الأذن نسمع بها الأصوات.', 'page' => 18],
            ['type' => 'vocab', 'content' => 'الأنف نشمّ به الروائح.', 'page' => 20],
            ['type' => 'vocab', 'content' => 'اللسان نتذوق به الطعم.', 'page' => 22],
            ['type' => 'vocab', 'content' => 'الجلد نلمس به الأشياء فنحس بالحرارة والنعومة.', 'page' => 24],
        ], [
            ['type' => 'mcq', 'q' => 'بماذا نسمع الأصوات؟', 'answer' => 'الأذن',
                'options' => [['text' => 'العين'], ['text' => 'الأذن', 'correct' => true], ['text' => 'الأنف']],
                'hint' => 'عضو السمع هو الأذن.', 'steps' => ['العين للرؤية.', 'الأذن للسمع.', 'إذن الجواب: الأذن.']],
        ]);

        $this->lesson($book, 'النبات ينمو', 25, 32, 3, [
            ['type' => 'heading', 'content' => 'كيف ينمو النبات؟', 'page' => 25],
            ['type' => 'point', 'content' => 'النبات يحتاج إلى الماء والهواء وضوء الشمس ليعيش.', 'page' => 27],
            ['type' => 'point', 'content' => 'النبات له جذور تمتص الماء من التربة.', 'page' => 29],
            ['type' => 'point', 'content' => 'النبات له أوراق وساق وأزهار.', 'page' => 31],
            ['type' => 'note', 'content' => 'نزرع حبة الفاصولياء ونسقيها لنشاهدها تنمو.', 'page' => 32],
        ], [
            ['type' => 'short_answer', 'q' => 'اذكر شيئين يحتاجهما النبات لينمو.', 'answer' => 'الماء والضوء',
                'steps' => ['من نقاط الدرس: النبات يحتاج الماء والهواء وضوء الشمس.'],
                'hint' => 'راجع نقاط الدرس عن احتياجات النبات.'],
        ]);
    }

    private function seedIslamic(Textbook $book): void
    {
        $this->lesson($book, 'سورة الفاتحة', 6, 12, 1, [
            ['type' => 'heading', 'content' => 'نتعلم سورة الفاتحة', 'page' => 6],
            ['type' => 'point', 'content' => 'سورة الفاتحة أول سورة في القرآن الكريم.', 'page' => 7],
            ['type' => 'point', 'content' => 'نقرؤها في كل ركعة من الصلاة.', 'page' => 8],
            ['type' => 'note', 'content' => 'نستمع إلى تلاوة المعلم ونردّد معه بتأنٍّ.', 'page' => 10],
        ], [
            ['type' => 'mcq', 'q' => 'ما ترتيب سورة الفاتحة في المصحف؟', 'answer' => 'الأولى',
                'options' => [['text' => 'الأولى', 'correct' => true], ['text' => 'الثانية'], ['text' => 'الأخيرة']],
                'hint' => 'الفاتحة هي أول سورة.', 'steps' => ['الفاتحة أول سورة في القرآن.', 'إذن الجواب: الأولى.']],
        ]);

        $this->lesson($book, 'أركان الإسلام', 13, 20, 2, [
            ['type' => 'heading', 'content' => 'نتعلم أركان الإسلام', 'page' => 13],
            ['type' => 'point', 'content' => 'أركان الإسلام خمسة.', 'page' => 14],
            ['type' => 'vocab', 'content' => 'الشهادتان: لا إله إلا الله محمد رسول الله.', 'page' => 15],
            ['type' => 'vocab', 'content' => 'الصلاة: نصلي خمس صلوات في اليوم.', 'page' => 16],
            ['type' => 'vocab', 'content' => 'الزكاة: نعطي الفقراء من مالنا.', 'page' => 18],
            ['type' => 'vocab', 'content' => 'الصوم: نصوم شهر رمضان.', 'page' => 19],
            ['type' => 'vocab', 'content' => 'الحج: نزور بيت الله الحرام.', 'page' => 20],
        ], [
            ['type' => 'numeric', 'q' => 'كم عدد أركان الإسلام؟', 'answer' => '5',
                'steps' => ['نعدّ الأركان: الشهادتان، الصلاة، الزكاة، الصوم، الحج.', 'المجموع خمسة.'],
                'hint' => 'راجع نقاط الدرس وعدّها.'],
        ]);

        $this->lesson($book, 'الوضوء', 21, 28, 3, [
            ['type' => 'heading', 'content' => 'نتعلم الوضوء', 'page' => 21],
            ['type' => 'definition', 'content' => 'الوضوء طهارة بالماء قبل الصلاة.', 'page' => 22],
            ['type' => 'point', 'content' => 'نبدأ بالنية ثم نغسل الكفّين.', 'page' => 23],
            ['type' => 'point', 'content' => 'نتمضمض ونستنشق، ثم نغسل الوجه.', 'page' => 24],
            ['type' => 'point', 'content' => 'نغسل اليدين إلى المرفقين ثم نمسح الرأس.', 'page' => 26],
            ['type' => 'point', 'content' => 'نغسل الرجلين إلى الكعبين.', 'page' => 28],
        ], [
            ['type' => 'ordering', 'q' => 'رتّب خطوات الوضوء: (غسل الوجه - النية - غسل الرجلين)', 'answer' => 'النية ثم غسل الوجه ثم غسل الرجلين',
                'steps' => ['أولًا النية.', 'ثانيًا غسل الوجه.', 'أخيرًا غسل الرجلين.'],
                'hint' => 'ابدأ بالنية.'],
        ]);
    }
}
