<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\LessonSection;
use Illuminate\Database\Seeder;

/**
 * Give every lesson an interactive model matched to its own topic.
 *
 * The maths/science books already carry hand-authored models (Grade12MathVisuals,
 * ScienceModels). This seeder closes the gap for the rest of the catalogue: it
 * reads each lesson's title and attaches the model that fits that topic — a 3-D
 * solid for «المجسمات», a tilted-axis globe for «الشمس والقمر», a ball-and-stick
 * molecule for «الروابط الكيميائية», a number line for «الأعداد الحقيقية».
 *
 * Honesty rules:
 *  - A model is only attached when the title names a topic we can draw. A row
 *    whose title is a structural label («أنشطة», «تقويم», «تمهيد») carries no
 *    topic, so it gets a neutral lesson-plan card instead of an invented figure.
 *  - The model's labels are the standard names for the parts; nothing claims to
 *    be quoted from the Yemeni edition.
 *
 * Idempotent: a lesson that already has a visual (from another seeder) is left
 * untouched; otherwise the section is created once and updated in place.
 */
class InteractiveModelsCoverageSeeder extends Seeder
{
    private const MARKER = 'نموذج الدرس';

    public function run(): void
    {
        $rules = $this->rules();
        $attached = 0;
        $skipped = 0;

        // Which lessons already carry a figure, read once instead of per lesson.
        $already = LessonSection::query()
            ->whereNotNull('visual')
            ->distinct()
            ->pluck('lesson_id')
            ->flip();

        Lesson::query()->orderBy('id')->chunk(200, function ($lessons) use ($rules, $already, &$attached, &$skipped) {
            foreach ($lessons as $lesson) {
                $models = $this->modelsFor((string) $lesson->title, $rules);
                if (! $models || $already->has($lesson->id)) {
                    $skipped++;

                    continue;
                }
                foreach ($models as $i => $model) {
                    LessonSection::updateOrCreate(
                        ['lesson_id' => $lesson->id, 'title' => self::MARKER.': '.$model['title']],
                        [
                            'type' => 'example',
                            'content' => $model['caption'],
                            'visual' => $model['spec'],
                            'is_from_textbook' => false,
                            'sort_order' => 950 + $i,
                        ],
                    );
                }
                $attached++;
            }
        });

        $this->command?->info("Interactive models attached to {$attached} lesson(s); {$skipped} left unchanged.");

        $this->upgradeSpatialTopics();
    }

    /**
     * A handful of topics are inherently spatial. Where such a lesson already
     * carries a flat figure from another seeder, add the three-dimensional model
     * as well, so the learner can rotate it. The lesson is left alone if it
     * already has a 3-D model, so this stays idempotent.
     */
    private function upgradeSpatialTopics(): void
    {
        $patterns = [];
        foreach ($this->spatialModels() as $pattern => $model) {
            $patterns[] = ['~'.$pattern.'~u', $model];
        }

        // Lessons that already carry a 3-D model, read once.
        $has3d = LessonSection::query()
            ->whereNotNull('visual')
            ->get(['lesson_id', 'visual'])
            ->filter(fn ($s) => is_array($s->visual) && str_ends_with((string) ($s->visual['kind'] ?? ''), '-3d'))
            ->pluck('lesson_id')
            ->flip();

        $upgraded = 0;
        Lesson::query()->orderBy('id')->chunk(200, function ($lessons) use ($patterns, $has3d, &$upgraded) {
            foreach ($lessons as $lesson) {
                $model = null;
                foreach ($patterns as [$regex, $candidate]) {
                    if (preg_match($regex, (string) $lesson->title)) {
                        $model = $candidate;
                        break;
                    }
                }
                if (! $model || $has3d->has($lesson->id)) {
                    continue;
                }
                LessonSection::updateOrCreate(
                    ['lesson_id' => $lesson->id, 'title' => self::MARKER.': '.$model['title']],
                    [
                        'type' => 'example',
                        'content' => $model['caption'],
                        'visual' => $model['spec'],
                        'is_from_textbook' => false,
                        'sort_order' => 940,
                    ],
                );
                $upgraded++;
            }
        });

        $this->command?->info("3-D models added to {$upgraded} spatial lesson(s).");
    }

    /** @return array<string, array{title:string,caption:string,spec:array}> */
    private function spatialModels(): array
    {
        return [
            'الذرة|مكونات الذرة|الجدول الدوري' => self::m('الذرة', 'النواة والإلكترونات في مستويات الطاقة.', 'atom-3d', ['z' => 6]),
            'الخلية|تركيب الخلية|الانقسام الخلوي|الغشاء الخلوي' => self::m('الخلية', 'النواة والعضيّات داخل الخلية.', 'cell-3d', ['plant' => true]),
            'الجزيء|الجزيئات|الروابط الكيميائية|الهيدروكربونات' => self::m('الجزيء', 'نموذج كرات وعصي لجزيء الماء.', 'molecule-3d', ['molecule' => 'water']),
            'أجزاء النبات|التغذية في النبات|التمثيل الضوئي|النبات ينمو' => self::m('أجزاء النبات', 'الجذر والساق والأوراق والزهرة.', 'plant-3d', ['show' => 'all']),
            'الشمس والقمر|النجوم|دوران الأرض|المجموعة الشمسية' => self::m('الأرض والشمس', 'ميل محور الأرض ينتج الفصول، ودورانها ينتج الليل والنهار.', 'earth-3d', ['tilt' => 23.5]),
            'المنشور|الطيف|الانكسار والعدسات|تحلل الضوء' => self::m('المنشور والضوء', 'تحلل الضوء الأبيض إلى ألوان الطيف.', 'prism-3d', ['angle' => 60]),
        ];
    }

    /**
     * First rule whose pattern matches the title wins.
     *
     * @param  array<int, array{0:string,1:array}>  $rules
     * @return array<int, array{title:string,caption:string,spec:array}>
     */
    private function modelsFor(string $title, array $rules): array
    {
        foreach ($rules as [$pattern, $models]) {
            if (preg_match($pattern, $title)) {
                return $models;
            }
        }

        return [];
    }

    private static function m(string $title, string $caption, string $kind, array $params = []): array
    {
        return ['title' => $title, 'caption' => $caption, 'spec' => ['kind' => $kind, 'params' => $params]];
    }

    /**
     * The rule table: [regex, models]. Ordered most-specific first; the first
     * match wins, so «الجمع والطرح حتى 20» must not fall through to a generic
     * «الجمع» rule that lacks the «حتى 20» bound.
     *
     * @return array<int, array{0:string,1:array}>
     */
    private function rules(): array
    {
        $r = [];
        $add = function (string $pattern, array $models) use (&$r) {
            $r[] = ['~'.$pattern.'~u', $models];
        };

        // ---- 3-D solids & geometry in space (maths) ------------------------
        $add('الحجوم الدورانية|الحجوم والمساحات', [self::m('الحجوم الدورانية', 'مجسّم دوراني من دوران منحنى حول المحور.', 'solid-of-revolution', ['fn' => 'x^2', 'a' => 0, 'b' => 2, 'n' => 8])]);
        $add('المجسمات', [self::m('المجسّمات', 'غيّر الشكل والأبعاد وراقب الحجم والمساحة.', 'solid-3d', ['shape' => 'cube', 'a' => 2, 'h' => 3])]);
        $add('المتجهات في الفضاء|الإحداثيات في الفضاء|المستقيمات والمستويات', [self::m('المتجهات في الفضاء', 'متجه في الفضاء ومساقطُه الثلاثة على المحاور.', 'vector-3d', ['x' => 2, 'y' => 3, 'z' => 2])]);
        $add('القطوع المخروطية|القطع المكافئ', [self::m('القطوع المخروطية', 'غيّر نوع القطع وراقب شكله ومعادلته.', 'conic', ['conic' => 'parabola'])]);
        $add('القطع الناقص', [self::m('القطع الناقص', 'القطع الناقص ومحوراه.', 'conic', ['conic' => 'ellipse'])]);
        $add('القطع الزائد', [self::m('القطع الزائد', 'القطع الزائد وفرعاه.', 'conic', ['conic' => 'hyperbola'])]);
        $add('الدائرة', [self::m('الدائرة', 'نصف القطر والمحيط والمساحة.', 'shapes', ['shape' => 'circle', 'r' => 2])]);
        $add('المحيط والمساحة', [self::m('المحيط والمساحة', 'غيّر الأبعاد وراقب المحيط والمساحة.', 'shapes', ['shape' => 'rectangle', 'a' => 4, 'b' => 3])]);
        $add('الأشكال الهندسية', [self::m('الأشكال الهندسية', 'اختر شكلًا وراقب أبعاده.', 'shapes', ['shape' => 'square', 'a' => 3])]);
        $add('الزوايا', [self::m('الزوايا', 'قِس الزاوية وأنواعها.', 'angles', ['deg' => 60])]);
        $add('المثلثات|حل المثلث القائم|النسب المثلثية|نظرية فيثاغورس', [self::m('المثلث', 'أضلاع المثلث وزواياه.', 'triangle', ['a' => 3, 'b' => 4])]);
        $add('الكسور المتكافئة|المقارنة بين الكسور|جمع وطرح الكسور', [self::m('الكسور', 'قارن الكسور وراقب البسط والمقام.', 'fractions', ['num' => 1, 'den' => 4])]);
        $add('الدائرة المثلثية', [self::m('الدائرة المثلثية', 'غيّر الزاوية وراقب الجيب وجيب التمام.', 'unit-circle', ['deg' => 30])]);
        $add('مبرهنة ذات الحدين', [self::m('مثلث باسكال', 'معاملات المفكوك في مثلث باسكال.', 'pascal', ['n' => 5])]);

        // ---- Functions, calculus, algebra -------------------------------
        $add('النهايات|نهايات واتصال|الاتصال', [self::m('النهاية والاتصال', 'سلوك الدالة قرب نقطة.', 'function-graph', ['fn' => 'x^2', 'at' => 1])]);
        $add('الاشتقاق|المشتقة|تطبيقات المشتقة|القيم العظمى والصغرى|دراسة تغيّر الدالة', [self::m('المشتقة والمماس', 'ميل المماس عند نقطة على المنحنى.', 'function-graph', ['fn' => 'x^3-3x', 'at' => 1])]);
        $add('التكامل|تطبيقات التكامل|حساب مساحات المناطق', [self::m('المساحة تحت المنحنى', 'نقارب المساحة بمستطيلات (مجموع ريمان).', 'area-under-curve', ['fn' => 'x^2', 'a' => 0, 'b' => 2, 'n' => 8])]);
        $add('اللوغاريتمية|الدالة الأسية|مشتقة الدالة اللوغاريتمية', [self::m('الدالة الأسية', 'المنحنى الأسي وميله.', 'function-graph', ['fn' => 'exp', 'at' => 0])]);
        $add('اللوغاريتم', [self::m('الدالة اللوغاريتمية', 'منحنى الدالة اللوغاريتمية.', 'function-graph', ['fn' => 'ln', 'at' => 1])]);
        $add('المتتابعة الهندسية', [self::m('المتتابعة الهندسية', 'حدود متتابعة هندسية بنسبة ثابتة.', 'bar-chart', ['labels' => ['ح١', 'ح٢', 'ح٣', 'ح٤'], 'data' => [2, 4, 8, 16]])]);
        $add('المتتابعة الحسابية|المتتابعات', [self::m('المتتابعة الحسابية', 'حدود متتابعة حسابية بفرق ثابت.', 'bar-chart', ['labels' => ['ح١', 'ح٢', 'ح٣', 'ح٤'], 'data' => [3, 6, 9, 12]])]);
        $add('الدالة التربيعية|المعادلات التربيعية', [self::m('الدالة التربيعية', 'منحنى الدالة التربيعية ورأسها.', 'function-graph', ['fn' => 'x^2', 'at' => 1])]);
        $add('الدالة الخطية|معادلة المستقيم', [self::m('الدالة الخطية', 'الميل ونقطة التقاطع.', 'function-graph', ['fn' => 'x^2', 'at' => 0])]);
        $add('المتباينات', [self::m('المتباينة', 'تمثيل المتباينة على مستقيم الأعداد.', 'number-line', ['a' => -2, 'b' => 3])]);
        $add('المصفوفات|المحددات', [self::m('المصفوفة', 'عناصر المصفوفة ومحدِّدها.', 'bar-chart', ['labels' => ['أ', 'ب', 'ج', 'د'], 'data' => [2, 5, 1, 4]])]);
        $add('الأعداد المركبة|المركبة وصورتها|جمع وضرب الأعداد المركبة|دوران المحاور', [self::m('العدد المركّب', 'العدد المركّب في المستوى.', 'complex-plane', ['re' => 2, 'im' => 3])]);
        $add('الأعداد الحقيقية|مجموعات الأعداد', [self::m('مستقيم الأعداد', 'تمثيل الأعداد على المستقيم.', 'number-line', ['a' => -3, 'b' => 4])]);

        // ---- Probability & statistics -----------------------------------
        $add('الاحتمال|التباديل والتوافيق|مبدأ العدّ|السحب مع الإعادة|الحوادث|التكرارات المستقلة', [self::m('شجرة الاحتمال', 'نتائج التجربة واحتمال كل فرع.', 'probability-tree', ['p' => 0.5])]);
        $add('المتوسط والوسيط|المتوسط والانحراف|جمع البيانات|الاستبيان|العينات|التمثيل البياني', [self::m('قراءة البيانات', 'المتوسط والوسيط لقراءة البيانات.', 'bar-chart', ['labels' => ['أ', 'ب', 'ج', 'د', 'هـ'], 'data' => [4, 7, 3, 8, 6]])]);

        // ---- Physics -----------------------------------------------------
        $add('الشمس والقمر|النجوم|المجموعة الشمسية|دوران الأرض|الفصول', [self::m('الأرض والشمس', 'ميل محور الأرض ينتج الفصول، ودورانها ينتج الليل والنهار.', 'earth-3d', ['tilt' => 23.5])]);
        $add('الذرة|مكونات الذرة|الجدول الدوري', [self::m('الذرة', 'النواة والإلكترونات في مستويات الطاقة.', 'atom-3d', ['z' => 6])]);
        $add('الروابط الكيميائية|الجزيء|الجزيئات', [self::m('الجزيء', 'نموذج كرات وعصي لجزيء الماء.', 'molecule-3d', ['molecule' => 'water'])]);
        $add('الضوء والانعكاس|انعكاس الضوء', [self::m('انعكاس الضوء', 'زاوية السقوط تساوي زاوية الانعكاس.', 'angles', ['deg' => 45])]);
        $add('الانكسار والعدسات|انكسار الضوء|الطيف|المنشور|تحلل الضوء|الليزر', [self::m('المنشور والضوء', 'تحلل الضوء الأبيض إلى ألوان الطيف.', 'prism-3d', ['angle' => 60])]);
        $add('الموجات الصوتية|الصوت|الموجات', [self::m('الموجة', 'غيّر السعة والطول الموجي وراقب التردّد.', 'wave', ['amp' => 1.5, 'lambda' => 2, 'speed' => 8])]);
        $add('الجهد والتيار|دوائر التوالي والتوازي|الكهرباء|الدائرة الكهربائية|الحث الكهرومغناطيسي|المحولات|الإلكترونيات|أشباه الموصلات', [self::m('الدائرة الكهربائية', 'الجهد والمقاومة والتيار.', 'circuit', ['mode' => 'series', 'v' => 12, 'r1' => 4, 'r2' => 6])]);
        $add('المجال المغناطيسي|المغناطيسية', [self::m('المجال المغناطيسي', 'خطوط المجال بين القطبين.', 'diagram', ['title' => 'المجال المغناطيسي', 'parts' => [['x' => 120, 'y' => 90, 'r' => 18, 'color' => '#fca5a5'], ['x' => 220, 'y' => 170, 'r' => 18, 'color' => '#93c5fd']]])]);
        $add('السرعة والتعجيل|معادلات الحركة|الحركة والقوة|قوانين نيوتن|الاحتكاك|التوازن', [self::m('القوة والحركة', 'غيّر القوة والكتلة وراقب التسارع.', 'vector-2d', ['u' => [4, 0], 'v' => [0, 3]])]);
        $add('الوزن والكتلة|الكتلة والوزن|الجاذبية', [self::m('الوزن والكتلة', 'الوزن يتغيّر بتغيّر الجاذبية.', 'bar-chart', ['labels' => ['الأرض', 'القمر', 'المريخ'], 'data' => [10, 1.6, 3.7]])]);
        $add('الشغل والقدرة|الطاقة الحركية والكامنة|حفظ الطاقة|الطاقة', [self::m('الطاقة', 'الطاقة تتحوّل من صورة إلى أخرى ولا تفنى.', 'bar-chart', ['labels' => ['كامنة', 'حركية', 'حرارية'], 'data' => [50, 30, 20]])]);
        $add('الحرارة|درجة الحرارة|التمدد', [self::m('الحرارة', 'انتقال الحرارة بين الأجسام.', 'bar-chart', ['labels' => ['سائل', 'صلب', 'غاز'], 'data' => [80, 100, 60]])]);

        // ---- Chemistry ---------------------------------------------------
        $add('حالات المادة|تغيّرات المادة', [self::m('حالات المادة', 'غيّر الحرارة وراقب الحالة.', 'states-of-matter', ['t' => 0.5])]);
        $add('الأحماض والقواعد|الأحماض العضوية|الكشف عن الأيونات|التحليل الحجمي', [self::m('الأحماض والقواعد', 'سلّم الحموضة والقلوية.', 'bar-chart', ['labels' => ['حمض', 'متعادل', 'قاعدة'], 'data' => [2, 7, 12]])]);
        $add('التفاعلات الكيميائية|المعادلات الكيميائية|الاتزان الكيميائي|سرعة التفاعل|الحسابات الكيميائية|الكيمياء الحرارية', [self::m('التفاعل الكيميائي', 'المتفاعلات والنواتج.', 'bar-chart', ['labels' => ['متفاعلات', 'نواتج'], 'data' => [40, 40]])]);
        $add('المحاليل|التراكيز|المول', [self::m('المحلول والتركيز', 'تغيّر التركيز بتغيّر المذاب.', 'bar-chart', ['labels' => ['مذيب', 'مذاب', 'محلول'], 'data' => [80, 20, 100]])]);
        $add('الهيدروكربونات|الكحولات|البوليمرات|الصناعات الكيميائية', [self::m('الهيدروكربونات', 'سلسلة مركبات الكربون.', 'molecule-3d', ['molecule' => 'methane'])]);
        $add('كيمياء البيئة|معالجة المياه|التلوث', [self::m('حماية البيئة', 'أثر التلوث على التوازن البيئي.', 'bar-chart', ['labels' => ['هواء', 'ماء', 'تربة'], 'data' => [60, 40, 30]])]);

        // ---- Biology -----------------------------------------------------
        $add('الخلية وتركيبها|تركيب الخلية|الغشاء الخلوي|الانقسام الخلوي|الانقسام', [self::m('الخلية', 'النواة والعضيّات داخل الخلية.', 'cell-3d', ['plant' => true])]);
        $add('النبات|أجزاء النبات|التغذية في النبات|التمثيل الضوئي|النبات ينمو|النباتات', [self::m('أجزاء النبات', 'الجذر والساق والأوراق والزهرة.', 'plant-3d', ['show' => 'all'])]);
        $add('الحمض النووي|الوراثة|الوراثة المندلية|الجينوم|الطفرات|الهندسة الوراثية|التقنية الحيوية', [self::m('الوراثة', 'انتقال الصفات عبر الأجيال.', 'bar-chart', ['labels' => ['سائد', 'متنحٍ'], 'data' => [3, 1]])]);
        $add('تصنيف الكائنات|الكائنات الحية|التنوع الحيوي|النظام البيئي|النظم البيئية|التوازن البيئي', [self::m('تصنيف الكائنات', 'مجاميع الكائنات الحية.', 'bar-chart', ['labels' => ['نبات', 'حيوان', 'فطريات', 'بدائيات'], 'data' => [4, 5, 2, 3]])]);
        $add('الجهاز الهضمي|التغذية|الجهاز التنفسي|الجهاز الدوري|الجهاز العصبي|الحواس|الإخراج|المناعة|الأمراض المعدية|الصحة العامة|الصحة النفسية', [self::m('أجهزة الجسم', 'وظائف أجهزة جسم الإنسان.', 'bar-chart', ['labels' => ['هضمي', 'تنفسي', 'دوري', 'عصبي'], 'data' => [4, 3, 5, 2]])]);
        $add('الهرمونات|الانفعالات|الدافعية|الشخصية|السلوك', [self::m('السلوك والدافعية', 'أنواع السلوك ودوافعه.', 'bar-chart', ['labels' => ['فطري', 'مكتسب'], 'data' => [5, 7]])]);

        // ---- Arabic language & grammar ----------------------------------
        $add('الحروف الهجائية|أحرف|خط الرقعة|خط النسخ|الحركات|المدود|المد بالألف|التاء المربوطة|التاء المفتوحة|اللام الشمسية|الهمزة المتوسطة|الهمزة المتطرفة|الألف اللينة', [self::m('بطاقة الحرف', 'شكل الحرف وصوته وموضعه.', 'letter-card', ['letter' => 'ب', 'sound' => 'بَ'])]);
        $add('قراءة كلمات بسيطة|قراءة جهرية|المفردات الجديدة|المفردات والسياق|الفكرة الرئيسة|التعبير عن الصور|التحليل والتركيب', [self::m('بطاقة الحرف', 'تعرّف على الحرف وصوته.', 'letter-card', ['letter' => 'م', 'sound' => 'مَ'])]);
        $add('كتابة جملة|كتابة فقرة|كتابة رسالة|الرسالة الرسمية|كتابة التقرير|كتابة البحث|كتابة القصة|أساليب الإنشاء|المقالة|وصف مشهد', [self::m('بناء الجملة', 'رتّب الكلمات لتكوين جملة سليمة.', 'sentence-builder', ['words' => ['الطالبُ', 'يقرأُ', 'الدرسَ'], 'answer' => 'الطالبُ يقرأُ الدرسَ', 'prompt' => 'رتّب الكلمات لتكوين جملة.'])]);
        $add('الجملة الاسمية|الجملة الفعلية|الفعل الماضي|الفعل المضارع|المرفوعات|المنصوبات|المجرورات|الأسماء الخمسة|الممنوع من الصرف|المعرب والمبني|الميزان الصرفي|النعت والحال|المفعول به|التوكيد|المبني للمجهول|التكافؤ', [self::m('البناء النحوي', 'صنّف الكلمة إلى اسم أو فعل.', 'sort-bins', ['bins' => ['اسم', 'فعل'], 'items' => ['كتاب', 'يقرأ', 'قلم', 'كتب', 'مدرسة', 'يلعب']])]);
        $add('التشبيه|الاستعارة|الكناية|المجاز|البلاغة|الإعجاز البياني|الصور البلاغية|المحسنات|الطباق|تحليل بلاغي', [self::m('البلاغة', 'صنّف الصورة البلاغية.', 'sort-bins', ['bins' => ['تشبيه', 'استعارة'], 'items' => ['كالبدر', 'أسدٌ يزأر', 'نور الهدى', 'كالنهر']])]);
        $add('الشعر|البحر|القافية|العروض|نصوص شعرية|الأدب|المذاهب الأدبية|الرومانسية|الواقعية|دراسة نص أدبي|التحليل الأدبي|التذوق الأدبي|المقالة الأدبية|النثر|الأساليب اللغوية', [self::m('تحليل النص الأدبي', 'خطوات تحليل النص الأدبي.', 'sequence', ['prompt' => 'رتّب خطوات تحليل النص.', 'steps' => ['قراءة النص', 'تحديد الفكرة', 'تحليل الألفاظ', 'استخراج الصور', 'الحكم والتقويم']])]);

        // ---- Islamic education ------------------------------------------
        $add('الوضوء|الطهارة|الصلاة|الزكاة|الصيام والحج|أركان الإسلام|فقه المعاملات|أحكام', [self::m('ترتيب العبادة', 'رتّب خطوات أداء العبادة.', 'sequence', ['prompt' => 'رتّب الخطوات بالترتيب الصحيح.', 'steps' => ['النية', 'الأداء', 'الترتيب', 'الختام']])]);
        $add('أسماء الله|الإيمان|أسباب النزول|أحكام التلاوة|تفسير سور|سورة|آيات', [self::m('الدرس القرآني', 'خطوات تدبّر الآيات.', 'sequence', ['prompt' => 'رتّب خطوات التدبّر.', 'steps' => ['التلاوة', 'التفسير', 'الفهم', 'التطبيق']])]);
        $add('سيرة النبي|غزوات|السيرة', [self::m('السيرة النبوية', 'محطات في السيرة النبوية.', 'sequence', ['prompt' => 'رتّب المحطات زمنيًا.', 'steps' => ['البعثة', 'الدعوة', 'الهجرة', 'بناء الدولة']])]);
        $add('الأمانة والصدق|العدل والتسامح|بر الوالدين|آداب|العدالة|الحرية|القيم|حقوق الإنسان', [self::m('القيم الأخلاقية', 'صنّف السلوك إلى قيمة حسنة وغير حسنة.', 'sort-bins', ['bins' => ['حسن', 'سيئ'], 'items' => ['الصدق', 'الكذب', 'الأمانة', 'الغش']])]);

        // ---- Social studies / history / geography ------------------------
        $add('المناخ|الغلاف الجوي|التضاريس|الغلاف الصخري|الموقع|الحدود|السكان|الموارد|الزراعة|الصناعة|التجارة|النشاط الاقتصادي|قراءة الخرائط|الإحداثيات|جغرافيا', [self::m('البيانات الجغرافية', 'قراءة الأرقام والمقارنة بينها.', 'bar-chart', ['labels' => ['السكان', 'الموارد', 'الأمطار'], 'data' => [7, 4, 6]])]);
        $add('الحضارات|الدولة الإسلامية|الخلافة|الدول اليمنية|مملكة سبأ|تاريخ|مصر القديمة|بلاد الرافدين|العولمة|الاستعمار|الاستقلال|الثورة|الوحدة|القضية الفلسطينية|الحرب|النهضة|التكامل العربي|عصر', [self::m('الخط الزمني', 'رتّب الأحداث زمنيًا.', 'sequence', ['prompt' => 'رتّب الأحداث من الأقدم إلى الأحدث.', 'steps' => ['الحضارات القديمة', 'العصر الإسلامي', 'العصر الحديث', 'المعاصر']])]);
        $add('الدستور|الحكم المحلي|المؤسسات|الحقوق والواجبات|المواطنة|الدولة|النقود|التغير الاجتماعي|التنشئة|الأسرة والمجتمع|التفكير|الاستقراء|الاستقلال|الحرية', [self::m('المفاهيم المدنية', 'صنّف المفاهيم.', 'sort-bins', ['bins' => ['حق', 'واجب'], 'items' => ['التعليم', 'الدفاع عن الوطن', 'الصحة', 'احترام القانون']])]);

        // ---- English -----------------------------------------------------
        $add('^The alphabet|^Numbers 1-20|Vocabulary|This is|Classroom objects|Family members|Greetings|Daily routine|Telling the time', [self::m('Vocabulary', 'Match the word to its group.', 'sort-bins', ['bins' => ['Person', 'Thing'], 'items' => ['teacher', 'book', 'student', 'pen']])]);
        $add('Present simple|Tenses|Passive voice|Modals|Conditionals|Relative clauses|Reported speech|Comparatives', [self::m('Sentence order', 'Put the words in the correct order.', 'sentence-builder', ['words' => ['She', 'reads', 'a', 'book'], 'answer' => 'She reads a book', 'prompt' => 'Arrange the words into a sentence.', 'dir' => 'ltr'])]);
        $add('Writing|Essay|Letter|Story|Summary|Comprehension|Inference|Long passages|Yemeni culture|World cultures', [self::m('Writing plan', 'Order the steps of a paragraph.', 'sequence', ['prompt' => 'Order the steps.', 'steps' => ['Topic sentence', 'Supporting idea', 'Example', 'Conclusion']])]);

        // ---- Early years (counting, time) --------------------------------
        $add('العدّ إلى|العد التصاعدي|الأعداد من|الجمع حتى ١٠|الطرح حتى ١٠|الجمع البسيط|الطرح البسيط|العد والقيمة|العدد والعدد', [self::m('العدّ', 'عُدّ العناصر واكتب العدد.', 'counting', ['n' => 5])]);
        $add('الجمع والطرح حتى 20|الجمع والطرح|الضرب|القسمة', [self::m('العدّ', 'مثّل عملية الجمع بالعناصر.', 'counting', ['n' => 8])]);
        $add('الوقت|الساعة|التقويم الزمني', [self::m('الساعة', 'اقرأ الساعة واضبط العقارب.', 'clock', ['hour' => 3, 'minute' => 30])]);
        $add('مدرستي|أسرتي وبيتي|أفراد الأسرة|الأسرة', [self::m('شجرة العائلة', 'أفراد الأسرة وصلاتهم.', 'family-tree', ['nodes' => ['الجد', 'الأب', 'الابن']])]);

        // ---- Generic study plan for structural rows ----------------------
        $add('.*', [self::m('خطوات دراسة الدرس', 'خطوات عامة لدراسة الدرس والاستعداد له.', 'sequence', ['prompt' => 'رتّب خطوات دراسة الدرس.', 'steps' => ['قراءة الدرس', 'فهم الأفكار', 'حل التطبيقات', 'المراجعة والتقويم']])]);

        return $r;
    }
}
