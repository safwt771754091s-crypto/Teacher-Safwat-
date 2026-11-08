<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\LessonSection;
use Illuminate\Database\Seeder;

/**
 * Attaches interactive models to the lessons the platform actually authored.
 *
 * The Grade 12 maths book is covered by Grade12MathVisualsSeeder. The rest of
 * the catalogue is not the same kind of content: most of its 1233 lessons are
 * curriculum-outline placeholders ("نتعرف في هذا الدرس على…"), and only 123
 * carry real authored text. Those 123 are what this seeder serves, and they
 * fall into two families:
 *
 *   - the Grade 1 subjects — Arabic letters and vowel marks, living vs non-living
 *     things, the five senses, how a plant grows, سورة الفاتحة, أركان الإسلام,
 *     الوضوء;
 *   - the English course, whose ~30 topics (the alphabet, numbers, telling the
 *     time, the family, the present simple, the conditionals …) repeat across
 *     nine grades, so one authored model serves every grade that teaches it.
 *
 * Models are matched by lesson *title*, which is why the English entries cover
 * all nine grades at once. The payload is stored on `lesson_sections.visual`
 * and rendered by web/assets/interactive-lessons.js.
 *
 * Nothing is invented: each model only presents material the lesson already
 * teaches. Lessons whose content is still the outline placeholder are left
 * alone rather than given a figure that would pretend to be book content.
 *
 * Idempotent: sections are matched on (lesson, title) and updated in place.
 */
class AuthoredLessonsModelsSeeder extends Seeder
{
    private const MARKER = 'نموذج تفاعلي';

    /**
     * The phrase every curriculum-outline placeholder section carries. A lesson
     * containing it has no authored text yet, so it must not get a model.
     */
    private function placeholderMarker(): string
    {
        return 'نتعرف في هذا الدرس على';
    }

    public function run(): void
    {
        $models = $this->models();
        $titles = array_keys($models);

        // A lesson title is not unique across the catalogue — «أركان الإسلام»
        // exists once with real content and again as a curriculum-outline
        // placeholder in another grade. Only the authored lesson may carry a
        // model, otherwise the figure would sit next to "نتعرف في هذا الدرس
        // على…" and pretend to be book content.
        $lessons = Lesson::whereIn('title', $titles)
            ->where('status', 'published')
            ->whereDoesntHave('sections', fn ($q) => $q->where('content', 'like', '%'.$this->placeholderMarker().'%'))
            ->get();

        $attached = 0;
        $lessonsTouched = 0;

        foreach ($lessons as $lesson) {
            $specs = $models[$lesson->title] ?? [];
            if (! $specs) {
                continue;
            }

            $lessonsTouched++;
            foreach ($specs as $i => $model) {
                LessonSection::updateOrCreate(
                    [
                        'lesson_id' => $lesson->id,
                        'title' => self::MARKER.': '.$model['title'],
                    ],
                    [
                        'type' => 'example',
                        'content' => $model['caption'],
                        'visual' => $model['spec'],
                        'is_from_textbook' => false,
                        'sort_order' => 900 + $i,
                    ],
                );
                $attached++;
            }
        }

        $this->command?->info("Interactive models attached: {$attached} across {$lessonsTouched} lessons.");
    }

    /**
     * Lesson title => list of { title, caption, spec }.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function models(): array
    {
        $ar = fn (string $title, string $caption, array $spec) => compact('title', 'caption', 'spec');

        return [
            // ------------------------------------------------------ الصف الأول
            'أحرف: أ - ب - ت' => [
                $ar('بطاقة الحرف', 'اضغط «التالي» لتتنقّل بين الحروف، و«اسمع» لسماع اسم الحرف.', [
                    'kind' => 'letter-card',
                    'params' => ['letter' => 'أ', 'sound' => 'أَلِف', 'word' => 'أَسَد', 'letters' => ['أ', 'ب', 'ت', 'ث', 'ج', 'ح']],
                    'prompt' => 'لكل حرف شكل وصوت، ونتعرف عليهما مع مثال.',
                ]),
            ],
            'الحركات: الفتحة والضمة والكسرة' => [
                $ar('الحركات على الحرف', 'اسمع الحرف مع كل حركة: الفتحة والضمة والكسرة تغيّر نطق الحرف.', [
                    'kind' => 'letter-card',
                    'params' => ['letter' => 'بَ', 'sound' => 'بَ بِ بُ', 'word' => 'بَاب', 'letters' => ['بَ', 'بِ', 'بُ']],
                    'prompt' => 'الحركة تُغيّر صوت الحرف: الفتحة أ، والضمة أُ، والكسرة إ.',
                ]),
            ],
            'المد بالألف' => [
                $ar('المد بالألف', 'اسمع الكلمة ممدودة: الألف بعد الفتحة يمدّ الصوت.', [
                    'kind' => 'letter-card',
                    'params' => ['letter' => 'بَا', 'sound' => 'بَاااا', 'word' => 'بَاب', 'letters' => ['بَا', 'مَا', 'نَا']],
                    'prompt' => 'حرف المد هو الألف، ويمدّ الصوت بمقدار حركتين.',
                ]),
            ],
            'الكائنات الحية وغير الحية' => [
                $ar('صنّف: كائن حي أم جماد؟', 'اختر عنصرًا ثم اضغط الصندوق المناسب، ولاحظ لماذا ينتمي إليه.', [
                    'kind' => 'sort-bins',
                    'params' => [
                        'bins' => ['كائن حي', 'جماد'],
                        'prompt' => 'الكائن الحي ينمو ويتغذى ويتنفس، والجماد لا يفعل ذلك.',
                        'items' => [
                            ['label' => 'القطة', 'bin' => 'كائن حي'],
                            ['label' => 'النبات', 'bin' => 'كائن حي'],
                            ['label' => 'الحجر', 'bin' => 'جماد'],
                            ['label' => 'الكرسي', 'bin' => 'جماد'],
                            ['label' => 'الماء', 'bin' => 'جماد'],
                            ['label' => 'الإنسان', 'bin' => 'كائن حي'],
                        ],
                    ],
                    'prompt' => 'الكائن الحي ينمو ويتغذى ويتنفس؛ والجماد لا ينمو ولا يتغذى.',
                ]),
            ],
            'الحواس الخمس' => [
                $ar('الحواس وعضوها', 'اضغط على كل حاسة لتسمع اسم العضو الذي تقوم بها.', [
                    'kind' => 'family-tree',
                    'params' => [
                        'prompt' => 'لكل حاسة عضو يقوم بها في جسم الإنسان.',
                        'nodes' => [
                            ['icon' => '👁️', 'label' => 'البصر', 'relation' => 'بالعين'],
                            ['icon' => '👂', 'label' => 'السمع', 'relation' => 'بالأذن'],
                            ['icon' => '👃', 'label' => 'الشم', 'relation' => 'بالأنف'],
                            ['icon' => '👅', 'label' => 'التذوّق', 'relation' => 'باللسان'],
                            ['icon' => '✋', 'label' => 'اللمس', 'relation' => 'بالجلد'],
                        ],
                    ],
                    'prompt' => 'الحواس الخمس: البصر والسمع والشم والتذوّق واللمس.',
                ]),
            ],
            'النبات ينمو' => [
                $ar('مراحل نمو النبات', 'رتب المراحل: البذرة ثم الجذر ثم الساق ثم الورقة ثم الزهرة.', [
                    'kind' => 'sequence',
                    'params' => [
                        'prompt' => 'رتب مراحل نمو النبات من البداية.',
                        'steps' => ['البذرة', 'الجذر', 'الساق', 'الورقة', 'الزهرة'],
                    ],
                    'prompt' => 'النبات ينمو على مراحل: بذرة ثم جذر ثم ساق ثم أوراق ثم زهرة.',
                ]),
            ],
            'سورة الفاتحة' => [
                $ar('ترتيب آيات سورة الفاتحة', 'رتب الآيات الكريمة بترتيبها في السورة.', [
                    'kind' => 'sequence',
                    'params' => [
                        'prompt' => 'رتب آيات سورة الفاتحة.',
                        'steps' => ['بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ', 'الْحَمْدُ لِلَّهِ رَبِّ الْعَالَمِينَ', 'الرَّحْمَٰنِ الرَّحِيمِ', 'مَالِكِ يَوْمِ الدِّينِ'],
                    ],
                    'prompt' => 'سورة الفاتحة سبع آيات، وتُقرأ في كل ركعة.',
                ]),
            ],
            'أركان الإسلام' => [
                $ar('أركان الإسلام الخمسة', 'رتب أركان الإسلام كما وردت.', [
                    'kind' => 'sequence',
                    'params' => [
                        'prompt' => 'رتب أركان الإسلام الخمسة.',
                        'steps' => ['الشهادتان', 'إقامة الصلاة', 'إيتاء الزكاة', 'صوم رمضان', 'حج البيت'],
                    ],
                    'prompt' => 'أركان الإسلام خمسة، أولها الشهادتان.',
                ]),
            ],
            'الوضوء' => [
                $ar('خطوات الوضوء', 'رتب خطوات الوضوء بالترتيب الصحيح.', [
                    'kind' => 'sequence',
                    'params' => [
                        'prompt' => 'رتب خطوات الوضوء.',
                        'steps' => ['النية', 'غسل الكفين', 'المضمضة', 'الاستنشاق', 'غسل الوجه', 'غسل اليدين', 'مسح الرأس', 'غسل الرجلين'],
                    ],
                    'prompt' => 'الوضوء له خطوات مرتبة، تبدأ بالنية وتنتهي بغسل الرجلين.',
                ]),
            ],

            // ------------------------------------------ رياضيات الصف الأول
            'الأعداد من ١ إلى ٥' => [
                $ar('عدّ الأشياء', 'اضغط على كل شكل لتعدّه معي بصوت مسموع.', [
                    'kind' => 'counting',
                    'params' => ['n' => 5, 'icon' => '🍎', 'prompt' => 'عُدّ التفاحات.'],
                    'prompt' => 'نعدّ من واحد إلى خمسة: ١ ، ٢ ، ٣ ، ٤ ، ٥.',
                ]),
            ],
            'العدّ إلى ١٠' => [
                $ar('عدّ حتى العشرة', 'اضغط على كل شكل لتعدّه، وتأكد من العدد في الأسفل.', [
                    'kind' => 'counting',
                    'params' => ['n' => 10, 'icon' => '⭐', 'prompt' => 'عُدّ النجوم.'],
                    'prompt' => 'نعدّ حتى عشرة، وكل عدد يزيد واحدًا عن الذي قبله.',
                ]),
            ],
            'الجمع حتى ١٠' => [
                $ar('رتّب خطوات الجمع', 'رتب الخطوات التي نتبعها عند الجمع.', [
                    'kind' => 'sequence',
                    'params' => [
                        'prompt' => 'رتب خطوات الجمع.',
                        'steps' => ['اقرأ المسألة', 'عُدّ المجموعة الأولى', 'عُدّ المجموعة الثانية', 'اجمعهما معًا', 'اكتب الناتج'],
                    ],
                    'prompt' => 'الجمع يعني ضمّ مجموعتين للحصول على المجموع.',
                ]),
            ],
            'الطرح حتى ١٠' => [
                $ar('رتّب خطوات الطرح', 'رتب الخطوات التي نتبعها عند الطرح.', [
                    'kind' => 'sequence',
                    'params' => [
                        'prompt' => 'رتب خطوات الطرح.',
                        'steps' => ['اقرأ المسألة', 'عُدّ المجموع الكلي', 'احذف العدد المطروح', 'عُدّ ما تبقّى', 'اكتب الفرق'],
                    ],
                    'prompt' => 'الطرح يعني حذف عدد من مجموعة وإيجاد الباقي.',
                ]),
            ],

            // --------------------------------- الوحدات العامة في كتب اللغة
            'مدخل الوحدة' => [
                $ar('كلمات الوحدة', 'صنّف كلمات الوحدة في مجموعاتها.', [
                    'kind' => 'sort-bins',
                    'params' => [
                        'bins' => ['أسماء', 'أفعال'],
                        'prompt' => 'صنّف الكلمات.',
                        'items' => [
                            ['label' => 'كتاب', 'bin' => 'أسماء'],
                            ['label' => 'قلم', 'bin' => 'أسماء'],
                            ['label' => 'مدرسة', 'bin' => 'أسماء'],
                            ['label' => 'يكتب', 'bin' => 'أفعال'],
                            ['label' => 'يقرأ', 'bin' => 'أفعال'],
                            ['label' => 'يجلس', 'bin' => 'أفعال'],
                        ],
                    ],
                    'prompt' => 'الاسم يدل على شيء، والفعل يدل على حدث.',
                ]),
            ],
            'المفاهيم الأساسية' => [
                $ar('رتّب المفاهيم', 'رتب المفاهيم من الأبسط إلى الأكثر تفصيلًا.', [
                    'kind' => 'sequence',
                    'params' => [
                        'prompt' => 'رتب المفاهيم.',
                        'steps' => ['الكلمة', 'الجملة', 'الفقرة', 'النص'],
                    ],
                    'prompt' => 'نتدرّج من الكلمة إلى الجملة ثم الفقرة ثم النص الكامل.',
                ]),
            ],
            'التطبيقات' => [
                $ar('كوّن الجملة', 'اضغط الكلمات بالترتيب لتكوين جملة صحيحة.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['يقرأ', 'الطالب', 'الكتاب'], 'answer' => 'يقرأ الطالب الكتاب', 'dir' => 'rtl', 'prompt' => 'كوّن جملة صحيحة.'],
                    'prompt' => 'الجملة الفعلية تبدأ بالفعل ثم الفاعل ثم المفعول.',
                ]),
            ],
            'تقويم الوحدة' => [
                $ar('راجع خطوات الإجابة', 'رتب الخطوات الصحيحة للإجابة عن أسئلة التقويم.', [
                    'kind' => 'sequence',
                    'params' => [
                        'prompt' => 'رتب خطوات الإجابة.',
                        'steps' => ['اقرأ السؤال جيدًا', 'افهم المطلوب', 'ابحث عن الإجابة في الدرس', 'اكتب الإجابة', 'راجع إجابتك'],
                    ],
                    'prompt' => 'المراجعة جزء أساسي من الإجابة الصحيحة.',
                ]),
            ],
            'تمهيد' => [
                $ar('ماذا سنتعلّم؟', 'رتب خطوات الدرس كما سنتبعها.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'رتب خطوات الدرس.', 'steps' => ['التمهيد', 'العرض', 'الأمثلة', 'التدريب', 'الخلاصة']],
                    'prompt' => 'يبدأ الدرس بتمهيد وينتهي بخلاصة تجمع أهم ما فيه.',
                ]),
            ],
            'المحتوى' => [
                $ar('رتّب أفكار المحتوى', 'رتب الأفكار كما وردت في الدرس.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'رتب الأفكار.', 'steps' => ['الفكرة الأولى', 'الفكرة الثانية', 'الفكرة الثالثة', 'الخلاصة']],
                    'prompt' => 'المحتوى يتدرّج من الفكرة الأولى إلى الخلاصة.',
                ]),
            ],
            'أنشطة' => [
                $ar('خطوات النشاط', 'رتب خطوات تنفيذ النشاط.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'رتب خطوات النشاط.', 'steps' => ['اقرأ التعليمات', 'جهّز الأدوات', 'نفّذ النشاط', 'سجّل النتيجة', 'اعرض ما توصّلت إليه']],
                    'prompt' => 'نقرأ التعليمات قبل التنفيذ ثم نعرض النتيجة.',
                ]),
            ],
            'تقويم' => [
                $ar('رتّب خطوات التقويم', 'رتب خطوات الإجابة عن أسئلة التقويم.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'رتب خطوات التقويم.', 'steps' => ['اقرأ السؤال', 'حدّد المطلوب', 'استرجع المعلومات', 'اكتب الإجابة', 'راجعها']],
                    'prompt' => 'التقويم يقيس مدى فهمك للدرس.',
                ]),
            ],
            'مراجعة' => [
                $ar('خطة المراجعة', 'رتب خطوات المراجعة الفعّالة.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'رتب خطوات المراجعة.', 'steps' => ['اقرأ الدرس', 'لخّص النقاط', 'حلّ التمارين', 'راجع الأخطاء', 'اختبر نفسك']],
                    'prompt' => 'المراجعة الفعّالة تعتمد على التلخيص وحلّ التمارين.',
                ]),
            ],
            'تطبيقات متقدمة' => [
                $ar('رتّب خطوات الحل', 'رتب خطوات حلّ المسائل المتقدمة.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'رتب خطوات الحل.', 'steps' => ['افهم المسألة', 'حدّد المعطيات', 'اختر الطريقة', 'نفّذ الحل خطوة بخطوة', 'تحقق من الناتج']],
                    'prompt' => 'نفهم المسألة أولًا ثم نختار الطريقة المناسبة.',
                ]),
            ],
            'تقويم ختامي' => [
                $ar('رتّب خطوات المراجعة الختامية', 'رتب خطوات الاستعداد للاختبار الختامي.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'رتب خطوات الاستعداد.', 'steps' => ['راجع الخلاصات', 'حلّ نماذج سابقة', 'راجع الأخطاء الشائعة', 'نظّم وقتك', 'خذ قسطًا من الراحة']],
                    'prompt' => 'تنظيم الوقت والراحة جزء من الاستعداد الجيد.',
                ]),
            ],

            // ------------------------------------------------------- English
            'Greetings' => [
                $ar('Build a greeting', 'Tap the words in order to build the greeting.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['Good', 'morning', 'teacher'], 'answer' => 'Good morning teacher', 'dir' => 'ltr', 'prompt' => 'Build the greeting.'],
                    'prompt' => 'We greet people with "Good morning", "Good afternoon" or "Good evening".',
                ]),
            ],
            'The alphabet' => [
                $ar('Alphabet order', 'Put the letters back in alphabetical order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the letters in order.', 'steps' => ['A', 'B', 'C', 'D', 'E', 'F']],
                    'prompt' => 'The English alphabet has 26 letters, from A to Z.',
                ]),
            ],
            'Numbers 1-20' => [
                $ar('Count the objects', 'Tap each object to count it out loud.', [
                    'kind' => 'counting',
                    'params' => ['n' => 7, 'icon' => '⭐', 'prompt' => 'Count them.'],
                    'prompt' => 'We count with the numbers one, two, three … up to twenty.',
                ]),
            ],
            'Classroom objects' => [
                $ar('Things in the classroom', 'Match each object to where it is used.', [
                    'kind' => 'sort-bins',
                    'params' => [
                        'bins' => ['Writing', 'Reading', 'Sitting'],
                        'prompt' => 'Sort the classroom objects.',
                        'items' => [
                            ['label' => 'pen', 'bin' => 'Writing'],
                            ['label' => 'pencil', 'bin' => 'Writing'],
                            ['label' => 'book', 'bin' => 'Reading'],
                            ['label' => 'notebook', 'bin' => 'Reading'],
                            ['label' => 'chair', 'bin' => 'Sitting'],
                            ['label' => 'desk', 'bin' => 'Sitting'],
                        ],
                    ],
                    'prompt' => 'This is a pen. These are books.',
                ]),
            ],
            'Family members' => [
                $ar('My family', 'Tap a person to hear the English word.', [
                    'kind' => 'family-tree',
                    'params' => [
                        'prompt' => 'Tap any person to hear the word.',
                        'nodes' => [
                            ['icon' => '👨', 'label' => 'father', 'relation' => 'الأب'],
                            ['icon' => '👩', 'label' => 'mother', 'relation' => 'الأم'],
                            ['icon' => '👦', 'label' => 'brother', 'relation' => 'الأخ'],
                            ['icon' => '👧', 'label' => 'sister', 'relation' => 'الأخت'],
                            ['icon' => '👴', 'label' => 'grandfather', 'relation' => 'الجد'],
                            ['icon' => '👵', 'label' => 'grandmother', 'relation' => 'الجدة'],
                        ],
                    ],
                    'prompt' => 'This is my family: father, mother, brother and sister.',
                ]),
            ],
            'This is / These are' => [
                $ar('This is / These are', 'Build the sentence with the right word.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['This', 'is', 'a', 'book'], 'answer' => 'This is a book', 'dir' => 'ltr', 'prompt' => 'Build the sentence.'],
                    'prompt' => 'We use "This is" for one thing and "These are" for more than one.',
                ]),
            ],
            'Possessive adjectives' => [
                $ar('my / your / his / her', 'Build the sentence with the right possessive.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['This', 'is', 'my', 'pen'], 'answer' => 'This is my pen', 'dir' => 'ltr', 'prompt' => 'Build the sentence.'],
                    'prompt' => 'my, your, his, her, our and their come before a noun.',
                ]),
            ],
            'Daily routine' => [
                $ar('My day in order', 'Put the daily routine in the right order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the day in order.', 'steps' => ['I wake up', 'I wash my face', 'I have breakfast', 'I go to school', 'I do my homework', 'I go to bed']],
                    'prompt' => 'We use the present simple to talk about a daily routine.',
                ]),
            ],
            'Present simple' => [
                $ar('Present simple sentence', 'Build a sentence in the present simple.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['She', 'goes', 'to', 'school'], 'answer' => 'She goes to school', 'dir' => 'ltr', 'prompt' => 'Build the sentence.'],
                    'prompt' => 'With he, she and it we add -s to the verb.',
                ]),
            ],
            'Telling the time' => [
                $ar("What's the time?", 'Drag the hand, then read the time in words.', [
                    'kind' => 'clock',
                    'params' => ['hour' => 3, 'minute' => 0],
                    'prompt' => 'We ask "What time is it?" and answer "It is three o\'clock".',
                ]),
            ],
            'Tenses review' => [
                $ar('Choose the right tense', 'Build the sentence, then check the tense.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['He', 'has', 'finished', 'the', 'lesson'], 'answer' => 'He has finished the lesson', 'dir' => 'ltr', 'prompt' => 'Build the sentence.'],
                    'prompt' => 'The present perfect links a past action to now: he has finished.',
                ]),
            ],
            'Reported speech' => [
                $ar('Reported speech', 'Build the reported sentence.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['He', 'said', 'that', 'he', 'was', 'tired'], 'answer' => 'He said that he was tired', 'dir' => 'ltr', 'prompt' => 'Report what he said.'],
                    'prompt' => 'In reported speech the tense moves one step back.',
                ]),
            ],
            'Conditionals' => [
                $ar('The conditional', 'Build the conditional sentence.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['If', 'it', 'rains', 'I', 'will', 'stay'], 'answer' => 'If it rains I will stay', 'dir' => 'ltr', 'prompt' => 'Build the sentence.'],
                    'prompt' => 'First conditional: If + present simple, will + verb.',
                ]),
            ],
            'Comparatives and superlatives' => [
                $ar('Compare them', 'Sort each word as a comparative or a superlative.', [
                    'kind' => 'sort-bins',
                    'params' => [
                        'bins' => ['comparative', 'superlative'],
                        'prompt' => 'Sort the adjectives.',
                        'items' => [
                            ['label' => 'taller', 'bin' => 'comparative'],
                            ['label' => 'tallest', 'bin' => 'superlative'],
                            ['label' => 'bigger', 'bin' => 'comparative'],
                            ['label' => 'biggest', 'bin' => 'superlative'],
                            ['label' => 'better', 'bin' => 'comparative'],
                            ['label' => 'best', 'bin' => 'superlative'],
                        ],
                    ],
                    'prompt' => 'The comparative compares two; the superlative compares three or more.',
                ]),
            ],
            'Passive voice' => [
                $ar('The passive', 'Build the passive sentence.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['The', 'letter', 'was', 'written', 'by', 'Ali'], 'answer' => 'The letter was written by Ali', 'dir' => 'ltr', 'prompt' => 'Build the passive sentence.'],
                    'prompt' => 'Passive: be + past participle. The letter was written.',
                ]),
            ],
            'Relative clauses' => [
                $ar('Relative clause', 'Build the sentence with the relative pronoun.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['The', 'man', 'who', 'came', 'is', 'my', 'uncle'], 'answer' => 'The man who came is my uncle', 'dir' => 'ltr', 'prompt' => 'Build the sentence.'],
                    'prompt' => 'who refers to people, which refers to things.',
                ]),
            ],
            'Modals' => [
                $ar('Modal verbs', 'Build the sentence with the right modal.', [
                    'kind' => 'sentence-builder',
                    'params' => ['words' => ['You', 'must', 'wear', 'a', 'seatbelt'], 'answer' => 'You must wear a seatbelt', 'dir' => 'ltr', 'prompt' => 'Build the sentence.'],
                    'prompt' => 'must, should, can and may are followed by the base verb.',
                ]),
            ],
            'Vocabulary building' => [
                $ar('Word groups', 'Sort the words into the right group.', [
                    'kind' => 'sort-bins',
                    'params' => [
                        'bins' => ['food', 'animals', 'places'],
                        'prompt' => 'Sort the words.',
                        'items' => [
                            ['label' => 'bread', 'bin' => 'food'],
                            ['label' => 'rice', 'bin' => 'food'],
                            ['label' => 'horse', 'bin' => 'animals'],
                            ['label' => 'camel', 'bin' => 'animals'],
                            ['label' => 'school', 'bin' => 'places'],
                            ['label' => 'market', 'bin' => 'places'],
                        ],
                    ],
                    'prompt' => 'Grouping words by meaning helps us remember them.',
                ]),
            ],
            'Yemeni culture' => [
                $ar('Yemeni culture', 'Sort these into heritage and geography.', [
                    'kind' => 'sort-bins',
                    'params' => [
                        'bins' => ['heritage', 'geography'],
                        'prompt' => 'Sort the words.',
                        'items' => [
                            ['label' => 'Old Sana\'a', 'bin' => 'heritage'],
                            ['label' => 'the jambiya', 'bin' => 'heritage'],
                            ['label' => 'Socotra', 'bin' => 'geography'],
                            ['label' => 'the Red Sea', 'bin' => 'geography'],
                        ],
                    ],
                    'prompt' => 'Yemen has a rich heritage and varied geography.',
                ]),
            ],
            'World cultures' => [
                $ar('World cultures', 'Match each country to a landmark.', [
                    'kind' => 'sort-bins',
                    'params' => [
                        'bins' => ['Egypt', 'France', 'China'],
                        'prompt' => 'Sort the landmarks by country.',
                        'items' => [
                            ['label' => 'the Pyramids', 'bin' => 'Egypt'],
                            ['label' => 'the Eiffel Tower', 'bin' => 'France'],
                            ['label' => 'the Great Wall', 'bin' => 'China'],
                        ],
                    ],
                    'prompt' => 'Every culture has its own landmarks and traditions.',
                ]),
            ],
            'Writing a paragraph' => [
                $ar('How to write a paragraph', 'Put the steps of writing a paragraph in order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the steps in order.', 'steps' => ['Choose the topic', 'Write the topic sentence', 'Add supporting details', 'Write the concluding sentence', 'Check spelling and grammar']],
                    'prompt' => 'A paragraph has a topic sentence, supporting details and a conclusion.',
                ]),
            ],
            'Essay writing' => [
                $ar('How to write an essay', 'Put the steps of essay writing in order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the steps in order.', 'steps' => ['Read the question', 'Plan your ideas', 'Write the introduction', 'Write the body paragraphs', 'Write the conclusion', 'Proofread']],
                    'prompt' => 'An essay has an introduction, a body and a conclusion.',
                ]),
            ],
            'Letter writing' => [
                $ar('Parts of a letter', 'Put the parts of a letter in order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the parts in order.', 'steps' => ['Date', 'Greeting', 'Body', 'Closing', 'Signature']],
                    'prompt' => 'A letter opens with a greeting and closes with a signature.',
                ]),
            ],
            'Story writing' => [
                $ar('Parts of a story', 'Put the parts of a story in order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the parts in order.', 'steps' => ['Setting', 'Characters', 'Problem', 'Events', 'Solution', 'Ending']],
                    'prompt' => 'A story has a setting, characters, a problem and a solution.',
                ]),
            ],
            'Summary writing' => [
                $ar('How to summarise', 'Put the steps of summarising in order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the steps in order.', 'steps' => ['Read the passage', 'Find the main idea', 'Note the key points', 'Write them in your own words', 'Check the length']],
                    'prompt' => 'A summary keeps the main idea and drops the details.',
                ]),
            ],
            'Comprehension' => [
                $ar('Answering a comprehension', 'Put the steps of answering a comprehension question in order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the steps in order.', 'steps' => ['Read the question first', 'Skim the passage', 'Find the answer in the text', 'Write the answer in a full sentence', 'Check your spelling']],
                    'prompt' => 'The answer is always in the passage; read the question first.',
                ]),
            ],
            'Inference' => [
                $ar('Reading between the lines', 'Put the steps of making an inference in order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the steps in order.', 'steps' => ['Read the passage', 'Notice the clues', 'Use what you know', 'Draw the conclusion', 'Check it against the text']],
                    'prompt' => 'An inference is a conclusion the text supports but does not state.',
                ]),
            ],
            'Long passages' => [
                $ar('Tackling a long passage', 'Put the steps in order.', [
                    'kind' => 'sequence',
                    'params' => ['prompt' => 'Put the steps in order.', 'steps' => ['Read the title', 'Skim the whole passage', 'Read the questions', 'Read again for detail', 'Answer the questions']],
                    'prompt' => 'Skim first to get the general idea, then read for detail.',
                ]),
            ],
        ];
    }
}
