<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\LessonSection;
use App\Models\Textbook;
use Illuminate\Database\Seeder;

/**
 * Attaches interactive models to the Grade 12 Mathematics book.
 *
 * The book's lessons used to be text only. Each entry here adds one or more
 * *manipulable* models (a draggable complex number, a sliding tangent, a Riemann
 * sum whose rectangles get thinner, a conic whose focus moves, Pascal's triangle,
 * a probability tree, the unit circle, a 3-D vector, a number line) to a lesson.
 *
 * The payload is stored on `lesson_sections.visual` as JSON and rendered by
 * web/assets/interactive.js, so this seeder only describes *what* to show, never
 * how to draw it. Every model is grounded in the lesson's own textbook content.
 *
 * Non-destructive and idempotent: sections are matched by (lesson, sort_order
 * slot) via a deterministic title, and re-running updates them in place.
 */
class Grade12MathVisualsSeeder extends Seeder
{
    /** A stable, human-readable title so re-runs update instead of duplicating. */
    private const MARKER = 'نموذج تفاعلي';

    public function run(): void
    {
        $book = Textbook::whereHas('subject.grade', fn ($q) => $q->where('level', 12))
            ->whereHas('subject', fn ($q) => $q->where('name', 'الرياضيات'))
            ->where('branch', 'scientific')
            ->first();

        if (! $book) {
            $this->command?->warn('Grade 12 scientific maths book not found; skipping interactive models.');

            return;
        }

        $lessons = Lesson::where('textbook_id', $book->id)
            ->where('status', 'published')
            ->get()
            ->keyBy('title');

        $attached = 0;
        foreach ($this->models() as $lessonTitle => $models) {
            $lesson = $lessons->get($lessonTitle);
            if (! $lesson) {
                $this->command?->warn("Lesson not found: {$lessonTitle}");

                continue;
            }

            foreach ($models as $i => $model) {
                LessonSection::updateOrCreate(
                    [
                        'lesson_id' => $lesson->id,
                        'title' => self::MARKER.' — '.($model['title'] ?? $model['spec']['kind']),
                    ],
                    [
                        'type' => 'example',
                        'content' => $model['caption'],
                        'visual' => $model['spec'],
                        // Slot after the authored text sections so the model reads
                        // as an illustration of what came before it.
                        'sort_order' => 900 + $i,
                        'is_from_textbook' => false,
                        'source_page' => null,
                    ],
                );
                $attached++;
            }
        }

        $this->command?->info("Interactive models attached: {$attached} across ".count($this->models()).' lessons.');
    }

    /**
     * Lesson title => list of { title, caption, spec }.
     *
     * `spec` is the JSON contract consumed by interactive.js:
     *   { kind, params, prompt }
     */
    private function models(): array
    {
        return [
            'مجموعة الأعداد المركبة وصورتها الجبرية' => [
                [
                    'title' => 'المستوى المركّب',
                    'caption' => 'اسحب العدد المركب ع في المستوى: يتغيّر جزؤه الحقيقي وجزؤه التخيلي ومعياره وزاويته، ويظهر مرافقه منعكسًا حول محور الأعداد الحقيقية.',
                    'spec' => [
                        'kind' => 'complex-plane',
                        'params' => ['re' => 3, 'im' => 4, 'range' => 6],
                        'prompt' => 'كل عدد مركب (س + ت ص) نقطة في المستوى: الإحداثي الأفقي هو الجزء الحقيقي، والرأسي هو الجزء التخيلي.',
                    ],
                ],
            ],

            'جمع وضرب الأعداد المركبة' => [
                [
                    'title' => 'الجمع على المستوى المركّب',
                    'caption' => 'غيّر العدد ع وراقب كيف يتغيّر مجموعه مع مرافقه؛ الجزءان الحقيقيان يُجمعان والجزءان التخيليان يُجمعان.',
                    'spec' => [
                        'kind' => 'complex-plane',
                        'params' => ['re' => -2, 'im' => 3, 'range' => 6],
                        'prompt' => 'عند الجمع نجمع الجزأين الحقيقيين معًا والجزأين التخيليين معًا.',
                    ],
                ],
            ],

            'القسمة والمرافق والصورة القطبية' => [
                [
                    'title' => 'المعيار والزاوية',
                    'caption' => 'اسحب العدد: راقب طول المتجه (المعيار) والزاوية التي يصنعها مع المحور الحقيقي، وهما أساس الصورة القطبية.',
                    'spec' => [
                        'kind' => 'complex-plane',
                        'params' => ['re' => 1, 'im' => 1.5, 'range' => 4],
                        'prompt' => 'الصورة القطبية تكتب العدد بدلالة معياره وزاويته: ع = |ع| (جتا هـ + ت جا هـ).',
                    ],
                ],
            ],

            'مبدأ العدّ' => [
                [
                    'title' => 'مبدأ العدّ على مستقيم الأعداد',
                    'caption' => 'حرّك الطرفين: طول الفترة يساوي عدد الاحتمالات المتاحة بينهما، وهذا هو جوهر مبدأ العدّ الأساسي (حاصل ضرب الخيارات).',
                    'spec' => [
                        'kind' => 'number-line',
                        'params' => ['a' => 1, 'b' => 6, 'lo' => 0, 'hi' => 8],
                        'prompt' => 'إذا أمكن إتمام عملية بـ م طريقة وأخرى بـ ن طريقة، فإتمامهما معًا بـ (م × ن) طريقة.',
                    ],
                ],
            ],

            'التباديل والتوافيق' => [
                [
                    'title' => 'مثلث باسكال والتوافيق',
                    'caption' => 'اختر الأس ن: المعاملات في الصف رقم ن هي قيم «ن ق ر» (التوافيق) بالترتيب، وهي أساس التباديل والتوافيق.',
                    'spec' => [
                        'kind' => 'pascal',
                        'params' => ['n' => 5],
                        'prompt' => 'التوافيق لا تعتمد على الترتيب، والتباديل تعتمد عليه؛ وكلاهما يُحسب من معاملات ذات الحدين.',
                    ],
                ],
            ],

            'مبرهنة ذات الحدين' => [
                [
                    'title' => 'مثلث باسكال',
                    'caption' => 'زد الأس ن: يظهر صف جديد من المعاملات، وكل معامل يساوي مجموع المعاملين فوقه مباشرة.',
                    'spec' => [
                        'kind' => 'pascal',
                        'params' => ['n' => 4],
                        'prompt' => 'معاملات مفكوك (أ + ب)ⁿ تُقرأ من مثلث باسكال مباشرة.',
                    ],
                ],
            ],

            'الاحتمال الشرطي وقانون الضرب' => [
                [
                    'title' => 'شجرة الاحتمالات',
                    'caption' => 'غيّر احتمالي الحدثين: يتغيّر احتمال كل مسار، وهو حاصل ضرب احتمالات فروعه (قانون الضرب).',
                    'spec' => [
                        'kind' => 'probability-tree',
                        'params' => ['p1' => 0.6, 'p2' => 0.5],
                        'prompt' => 'احتمال مسار كامل = حاصل ضرب احتمالات فروعه.',
                    ],
                ],
            ],

            'الحوادث المستقلة' => [
                [
                    'title' => 'استقلال الحوادث',
                    'caption' => 'عندما يكون الاحتمال الشرطي مساويًا للاحتمال المطلق يقال إن الحدثين مستقلان؛ جرّب قيمة تجعل الضرب يساوي الاحتمال المطلق.',
                    'spec' => [
                        'kind' => 'probability-tree',
                        'params' => ['p1' => 0.5, 'p2' => 0.5],
                        'prompt' => 'الحدثان مستقلان إذا كان: ح(ح₁ ∩ ح₂) = ح(ح₁) × ح(ح₂).',
                    ],
                ],
            ],

            'التكرارات المستقلة وقانون الاحتمال الثنائي' => [
                [
                    'title' => 'التجربة الثنائية',
                    'caption' => 'غيّر احتمال النجاح وعدد المحاولات: تتغيّر احتمالات المسارات، ومجموعها يبقى واحدًا.',
                    'spec' => [
                        'kind' => 'probability-tree',
                        'params' => ['p1' => 0.7, 'p2' => 0.7],
                        'prompt' => 'في التجربة ذات النتيجتين يكون احتمال النجاح ثابتًا في كل محاولة.',
                    ],
                ],
            ],

            'السحب مع الإعادة وبدون إعادة' => [
                [
                    'title' => 'السحب مع الإعادة وبدونها',
                    'caption' => 'اجعل الاحتمال الثاني مساويًا للأول (سحب مع الإعادة) أو مختلفًا عنه (سحب بدون إعادة)، وراقب أثر ذلك على المسارات.',
                    'spec' => [
                        'kind' => 'probability-tree',
                        'params' => ['p1' => 0.5, 'p2' => 0.4],
                        'prompt' => 'مع الإعادة يبقى الاحتمال ثابتًا، وبدونها يتغيّر بعد كل سحب.',
                    ],
                ],
            ],

            'القطع المكافئ' => [
                [
                    'title' => 'البؤرة والدليل',
                    'caption' => 'غيّر البعد البؤري ب: تتحرّك البؤرة ويقترب الدليل أو يبتعد، وتبقى كل نقطة على القطع على بُعد متساوٍ منهما.',
                    'spec' => [
                        'kind' => 'conic',
                        'params' => ['conic' => 'parabola', 'a' => 2],
                        'prompt' => 'القطع المكافئ: ص² = ٤ ب س، بؤرته (ب، ٠) ودليله س = −ب.',
                    ],
                ],
            ],

            'القطع الناقص' => [
                [
                    'title' => 'محورا القطع الناقص',
                    'caption' => 'غيّر المحور الأكبر والمحور الأصغر: تتحرّك البؤرتان، ويبقى مجموع بُعدي أي نقطة عنهما ثابتًا.',
                    'spec' => [
                        'kind' => 'conic',
                        'params' => ['conic' => 'ellipse', 'a' => 3, 'b' => 2],
                        'prompt' => 'القطع الناقص: مجموع بُعدي أي نقطة عن البؤرتين مقدار ثابت.',
                    ],
                ],
            ],

            'القطع الزائد' => [
                [
                    'title' => 'الخطان التقاربيان',
                    'caption' => 'غيّر المحورين: يقترب المنحنى من خطّين مستقيمين دون أن يلمسهما، وهما الخطّان التقاربيان.',
                    'spec' => [
                        'kind' => 'conic',
                        'params' => ['conic' => 'hyperbola', 'a' => 2, 'b' => 1.5],
                        'prompt' => 'القطع الزائد: فرق بُعدي أي نقطة عن البؤرتين ثابت، وله خطان تقاربيان.',
                    ],
                ],
            ],

            'دوران المحاور الإحداثية' => [
                [
                    'title' => 'الدائرة الوحدة وجيوب الزوايا',
                    'caption' => 'حرّك الزاوية على الدائرة الوحدة: جتا هي الإحداثي الأفقي وجا هو الرأسي، وهذا أساس تبسيط المعادلات بدوران المحاور.',
                    'spec' => [
                        'kind' => 'unit-circle',
                        'params' => ['deg' => 30],
                        'prompt' => 'الزاوية تُقاس من المحور الحقيقي، وجا² + جتا² = ١ دائمًا.',
                    ],
                ],
            ],

            'المستقيمات والمستويات في الفضاء' => [
                [
                    'title' => 'المتجهات في الفضاء',
                    'caption' => 'غيّر المركّبات الثلاث: يتغيّر موضع النقطة في الفضاء وطول المتجه، وهي الأساس لوصف المستقيمات والمستويات.',
                    'spec' => [
                        'kind' => 'vector-space',
                        'params' => ['x' => 2, 'y' => 3, 'z' => 1.5],
                        'prompt' => 'كل نقطة في الفضاء تُحدَّد بثلاث مركّبات (س، ص، ع).',
                    ],
                ],
            ],

            'المتجهات في الفضاء' => [
                [
                    'title' => 'طول المتجه',
                    'caption' => 'غيّر المركّبات وراقب الطول: |ع⃗| = √(س² + ص² + ع²).',
                    'spec' => [
                        'kind' => 'vector-space',
                        'params' => ['x' => 3, 'y' => 2, 'z' => 2],
                        'prompt' => 'طول المتجه يساوي الجذر التربيعي لمجموع مربّعات مركّباته.',
                    ],
                ],
            ],

            'الحجوم والمساحات في الفضاء' => [
                [
                    'title' => 'المتجهات في الفضاء وطولها',
                    'caption' => 'حرّك المركّبات في الفضاء: طول المتجه هو المسافة بين نقطتين، وهو أساس حساب المساحات والحجوم في الفضاء.',
                    'spec' => [
                        'kind' => 'vector-space',
                        'params' => ['x' => 3, 'y' => 3, 'z' => 2],
                        'prompt' => 'المسافة بين نقطتين في الفضاء = طول متجه الفرق بينهما.',
                    ],
                ],
            ],

            'نهايات واتصال الدوال' => [
                [
                    'title' => 'سلوك الدالة قرب نقطة',
                    'caption' => 'اقترب من النقطة: القيمة تقترب من نهاية معيّنة، وهذا هو معنى النهاية.',
                    'spec' => [
                        'kind' => 'function-graph',
                        'params' => ['fn' => 'x^3-3x', 'at' => 0.5, 'window' => ['x' => [-2.5, 2.5], 'y' => [-4, 4]]],
                        'prompt' => 'النهاية هي القيمة التي تقترب منها الدالة عند اقتراب س من نقطة معيّنة.',
                    ],
                ],
            ],

            'مشتقة الدالة اللوغاريتمية والأسية' => [
                [
                    'title' => 'المماس عند كل نقطة',
                    'caption' => 'حرّك نقطة التماس على المنحنى الأسّي: ميل المماس يساوي قيمة الدالة نفسها.',
                    'spec' => [
                        'kind' => 'function-graph',
                        'params' => ['fn' => 'exp', 'at' => 0.5, 'window' => ['x' => [-2, 2.5], 'y' => [-1, 8]]],
                        'prompt' => 'مشتقة الدالة الأسية هـ^س هي هـ^س نفسها.',
                    ],
                ],
            ],

            'مشتقة الدوال المثلثية' => [
                [
                    'title' => 'ميل المماس للجيب',
                    'caption' => 'حرّك المماس على منحنى جا س: الميل يساوي جتا س عند نفس النقطة.',
                    'spec' => [
                        'kind' => 'function-graph',
                        'params' => ['fn' => 'sin', 'at' => 0.5, 'window' => ['x' => [-6.5, 6.5], 'y' => [-1.5, 1.5]]],
                        'prompt' => 'مشتقة جا س هي جتا س.',
                    ],
                ],
            ],

            'دراسة تغيّر الدالة' => [
                [
                    'title' => 'التزايد والتناقص',
                    'caption' => 'حرّك المماس: عندما يكون الميل موجبًا فالدالة متزايدة، وعندما يكون سالبًا فهي متناقصة، وعند الصفر يوجد نقطة حرجة.',
                    'spec' => [
                        'kind' => 'function-graph',
                        'params' => ['fn' => 'x^3-3x', 'at' => 1, 'window' => ['x' => [-2.5, 2.5], 'y' => [-4, 4]]],
                        'prompt' => 'إشارة المشتقة تحدّد تزايد الدالة أو تناقصها.',
                    ],
                ],
            ],

            'تكامل الدوال الكسرية' => [
                [
                    'title' => 'مساحة تحت ١/س',
                    'caption' => 'زد عدد المستطيلات: يقترب المجموع من المساحة تحت المنحنى، وهي أساس تكامل الدوال الكسرية.',
                    'spec' => [
                        'kind' => 'area-under-curve',
                        'params' => ['fn' => '1/x', 'a' => 1, 'b' => 3, 'n' => 6],
                        'prompt' => 'التكامل هو نهاية مجموع مساحات المستطيلات عند زيادة عددها.',
                    ],
                ],
            ],

            'حساب مساحات المناطق المستوية' => [
                [
                    'title' => 'المساحة تحت المنحنى',
                    'caption' => 'زد عدد المستطيلات ن: يقترب مجموع ريمان من المساحة الحقيقية تحت المنحنى.',
                    'spec' => [
                        'kind' => 'area-under-curve',
                        'params' => ['fn' => 'x^2', 'a' => 0, 'b' => 3, 'n' => 4],
                        'prompt' => 'مساحة المنطقة المستوية تساوي التكامل المحدّد للدالة بين الطرفين.',
                    ],
                ],
            ],

            'الحجوم الدورانية' => [
                [
                    'title' => 'المستطيلات الدوّارة',
                    'caption' => 'كل مستطيل عند دورانه حول المحور يولّد أسطوانة؛ ومجموع حجومها يقترب من الحجم الدوراني.',
                    'spec' => [
                        'kind' => 'area-under-curve',
                        'params' => ['fn' => 'sqrt', 'a' => 0, 'b' => 4, 'n' => 6],
                        'prompt' => 'الحجم الدوراني يساوي تكامل مساحات المقاطع الدائرية.',
                    ],
                ],
            ],
        ];
    }
}
