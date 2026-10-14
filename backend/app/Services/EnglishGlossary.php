<?php

namespace App\Services;

/**
 * English support for the language subjects.
 *
 * The platform explains every lesson in Arabic. For the English language
 * subject it additionally reads the textbook's English words aloud and shows
 * their Arabic meaning, e.g. "Hello — مرحبًا", so a learner hears the English
 * word and understands it in Arabic.
 *
 * The glossary maps the words that actually appear in the Yemeni English
 * syllabus (grades 1–12) to their Arabic meaning. Lookups are case-insensitive
 * and fall back to a per-word translation so an unmapped word still yields the
 * Arabic label next to its English text.
 */
class EnglishGlossary
{
    /** @var array<string, string> lowercase English term => Arabic meaning */
    private const MAP = [
        // Greetings & classroom
        'hello' => 'مرحبًا', 'hi' => 'مرحبًا', 'good morning' => 'صباح الخير',
        'good afternoon' => 'مساء الخير', 'good evening' => 'مساء الخير',
        'goodbye' => 'إلى اللقاء', 'bye' => 'إلى اللقاء', 'welcome' => 'أهلًا وسهلًا',
        'greetings' => 'التحيات', 'greeting' => 'التحية', 'introduce' => 'يُعرّف بنفسه',
        'introduction' => 'التعريف بالنفس', 'meeting' => 'اللقاء',
        'please' => 'من فضلك', 'thank you' => 'شكرًا', 'thanks' => 'شكرًا',
        'sorry' => 'آسف', 'yes' => 'نعم', 'no' => 'لا', 'name' => 'الاسم',
        'classroom' => 'الصف الدراسي', 'class' => 'الصف', 'school' => 'المدرسة',
        'book' => 'الكتاب', 'pen' => 'القلم', 'pencil' => 'قلم الرصاص',
        'bag' => 'الحقيبة', 'desk' => 'الطاولة', 'chair' => 'الكرسي',
        'board' => 'السبورة', 'teacher' => 'المعلم', 'student' => 'الطالب',
        'door' => 'الباب', 'window' => 'النافذة', 'table' => 'الطاولة',
        // The alphabet & numbers
        'alphabet' => 'الأبجدية', 'letter' => 'حرف', 'word' => 'كلمة',
        'number' => 'رقم', 'numbers' => 'أرقام', 'one' => 'واحد', 'two' => 'اثنان',
        'three' => 'ثلاثة', 'four' => 'أربعة', 'five' => 'خمسة', 'six' => 'ستة',
        'seven' => 'سبعة', 'eight' => 'ثمانية', 'nine' => 'تسعة', 'ten' => 'عشرة',
        'colours' => 'الألوان', 'colors' => 'الألوان', 'red' => 'أحمر', 'blue' => 'أزرق',
        'green' => 'أخضر', 'yellow' => 'أصفر', 'black' => 'أسود', 'white' => 'أبيض',
        // Family & people
        'family' => 'العائلة', 'father' => 'الأب', 'mother' => 'الأم',
        'brother' => 'الأخ', 'sister' => 'الأخت', 'grandfather' => 'الجد',
        'grandmother' => 'الجدة', 'friend' => 'الصديق', 'friends' => 'الأصدقاء',
        'boy' => 'ولد', 'girl' => 'بنت', 'man' => 'رجل', 'woman' => 'امرأة',
        'child' => 'طفل', 'children' => 'أطفال', 'baby' => 'طفل صغير',
        // Daily life, time, food
        'day' => 'يوم', 'today' => 'اليوم', 'tomorrow' => 'غدًا', 'yesterday' => 'أمس',
        'morning' => 'الصباح', 'evening' => 'المساء', 'night' => 'الليل',
        'time' => 'الوقت', 'hour' => 'ساعة', 'minute' => 'دقيقة', 'week' => 'أسبوع',
        'month' => 'شهر', 'year' => 'سنة', 'weekdays' => 'أيام الأسبوع',
        'food' => 'الطعام', 'water' => 'الماء', 'milk' => 'الحليب', 'bread' => 'الخبز',
        'rice' => 'الأرز', 'apple' => 'التفاح', 'banana' => 'الموز', 'egg' => 'البيضة',
        'meat' => 'اللحم', 'fish' => 'السمك', 'breakfast' => 'الفطور',
        'lunch' => 'الغداء', 'dinner' => 'العشاء', 'fruit' => 'الفاكهة',
        'vegetables' => 'الخضروات', 'health' => 'الصحة', 'healthy' => 'صحي',
        // Home, country, places
        'house' => 'المنزل', 'home' => 'البيت', 'room' => 'الغرفة', 'city' => 'المدينة',
        'village' => 'القرية', 'country' => 'البلد', 'street' => 'الشارع',
        'market' => 'السوق', 'hospital' => 'المستشفى', 'mosque' => 'المسجد',
        'yemen' => 'اليمن', 'yemeni' => 'يمني', 'culture' => 'الثقافة',
        'world' => 'العالم', 'people' => 'الناس',
        // Weather, nature, animals
        'weather' => 'الطقس', 'sun' => 'الشمس', 'rain' => 'المطر', 'wind' => 'الرياح',
        'hot' => 'حار', 'cold' => 'بارد', 'animal' => 'الحيوان', 'animals' => 'الحيوانات',
        'cat' => 'القطة', 'dog' => 'الكلب', 'bird' => 'الطائر', 'tree' => 'الشجرة',
        'flower' => 'الزهرة', 'sea' => 'البحر', 'mountain' => 'الجبل',
        // Verbs & grammar terms
        'is' => 'يكون', 'are' => 'يكونون', 'am' => 'أكون', 'this' => 'هذا',
        'these' => 'هؤلاء', 'that' => 'ذلك', 'those' => 'أولئك', 'have' => 'يملك',
        'has' => 'يملك', 'go' => 'يذهب', 'goes' => 'يذهب', 'come' => 'يأتي',
        'read' => 'يقرأ', 'write' => 'يكتب', 'listen' => 'يستمع', 'speak' => 'يتحدث',
        'eat' => 'يأكل', 'drink' => 'يشرب', 'play' => 'يلعب', 'study' => 'يدرس',
        'work' => 'يعمل', 'sleep' => 'ينام', 'wake up' => 'يستيقظ',
        'present simple' => 'المضارع البسيط', 'past' => 'الماضي', 'future' => 'المستقبل',
        'tense' => 'الزمن', 'tenses' => 'الأزمنة', 'tense review' => 'مراجعة الأزمنة',
        'grammar' => 'القواعد', 'vocabulary' => 'المفردات', 'sentence' => 'الجملة',
        'sentences' => 'الجمل', 'question' => 'السؤال', 'answer' => 'الجواب',
        'noun' => 'الاسم', 'verb' => 'الفعل', 'adjective' => 'الصفة',
        'possessive adjectives' => 'صفات الملكية', 'comparatives' => 'المقارنة',
        'superlatives' => 'التفضيل', 'comparatives and superlatives' => 'المقارنة والتفضيل',
        'relative clauses' => 'الجمل الموصولة', 'reported speech' => 'الكلام المنقول',
        'passive voice' => 'المبني للمجهول', 'conditionals' => 'الجمل الشرطية',
        'modals' => 'الأفعال الناقصة', 'prepositions' => 'حروف الجر',
        // Reading & writing skills
        'reading' => 'القراءة', 'writing' => 'الكتابة', 'listening' => 'الاستماع',
        'speaking' => 'التحدث', 'comprehension' => 'الفهم والاستيعاب',
        'reading comprehension' => 'فهم المقروء', 'inference' => 'الاستنتاج',
        'summary' => 'الملخص', 'summary writing' => 'كتابة الملخص',
        'paragraph' => 'الفقرة', 'writing a paragraph' => 'كتابة فقرة',
        'essay' => 'المقال', 'essay writing' => 'كتابة مقال',
        'letter' => 'الرسالة', 'letter writing' => 'كتابة رسالة',
        'story' => 'القصة', 'story writing' => 'كتابة قصة',
        'long passages' => 'النصوص الطويلة', 'short sentences' => 'جمل قصيرة',
        'simple words' => 'كلمات بسيطة', 'vocabulary building' => 'بناء المفردات',
        'practice' => 'تدريب', 'exercises' => 'تمارين', 'review' => 'مراجعة',
        'unit' => 'الوحدة', 'lesson' => 'الدرس', 'test' => 'اختبار',
        'daily routine' => 'الروتين اليومي', 'telling the time' => 'إخبار الوقت',
        'family members' => 'أفراد الأسرة', 'classroom objects' => 'أدوات الصف',
        'my day' => 'يومي', 'my family' => 'عائلتي', 'my school' => 'مدرستي',
        'my country' => 'بلدي', 'world cultures' => 'ثقافات العالم',
        'literature' => 'الأدب', 'advanced grammar' => 'القواعد المتقدمة',
    ];

    /**
     * Arabic meaning for an English term, or null when unknown.
     */
    public function meaning(string $term): ?string
    {
        $key = $this->normalize($term);

        return self::MAP[$key] ?? null;
    }

    /** Lowercase and strip surrounding punctuation so "Hello!" matches "hello". */
    private function normalize(string $term): string
    {
        $key = mb_strtolower(trim($term));
        $key = preg_replace('/^[^a-z]+|[^a-z]+$/u', '', $key);

        return (string) $key;
    }

    /**
     * All English terms with their Arabic meaning, for building vocabulary games.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return self::MAP;
    }

    /**
     * Bilingual line for a single English term: "Hello — مرحبًا".
     */
    public function bilingual(string $term): string
    {
        $meaning = $this->meaning($term);

        return $meaning ? trim($term) . ' — ' . $meaning : trim($term);
    }

    /**
     * True when the lesson/section text looks like English (Latin letters), so
     * the narrator should read it in English rather than Arabic.
     */
    public function isEnglish(string $text): bool
    {
        $latin = preg_match_all('/[A-Za-z]/', $text);
        $arabic = preg_match_all('/\p{Arabic}/u', $text);

        return $latin > 0 && $latin >= $arabic;
    }

    /**
     * Bilingual explanation points for an English-language lesson: each point
     * gives the Arabic framing, the English textbook word, and its translation.
     *
     * @param  array<int, string>  $terms  English lesson/unit titles
     * @return array<int, string>
     */
    public function bilingualPoints(string $lessonTitle, array $terms = []): array
    {
        $points = [];
        $points[] = 'شرح الدرس بالعربية، مع قراءة الكلمات الإنجليزية من الكتاب وترجمتها.';

        $meaning = $this->meaning($lessonTitle);
        if ($meaning) {
            $points[] = $lessonTitle . ' — ' . $meaning . ' (نقرؤها بالإنجليزية ثم نترجمها إلى العربية).';
        } else {
            $points[] = $lessonTitle . ' (عنوان الدرس بالإنجليزية، نقرؤه ثم نشرحه بالعربية).';
        }

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '' || mb_strtolower($term) === mb_strtolower($lessonTitle)) {
                continue;
            }
            $points[] = $this->bilingual($term);
        }

        return $points;
    }
}
