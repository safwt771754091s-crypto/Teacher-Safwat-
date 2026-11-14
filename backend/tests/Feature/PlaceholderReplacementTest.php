<?php

namespace Tests\Feature;

use App\Console\Commands\ImportTextbooks;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\Textbook;
use App\Models\TextbookPage;
use App\Services\ContentExtractionService;
use Database\Seeders\FullCurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The catalogue is seeded as an outline: every lesson carries the «نتعرف في هذا
 * الدرس على…» / «محتوى هيكلي من فهرس المنهج» stub. Real book text must replace
 * that outline, and the outline must not come back on top of real content.
 */
class PlaceholderReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_placeholder_page_text_is_recognised(): void
    {
        $extractor = app(ContentExtractionService::class);

        $this->assertTrue($extractor->isPlaceholderPageText(
            'محتوى هيكلي من فهرس المنهج اليمني الرسمي، يُستكمل بنص الطبعة الرسمية فور رفعها.'
        ));
        $this->assertTrue($extractor->isPlaceholderPageText(
            'نتعرف في هذا الدرس على «مدخل الوحدة» ونستخرج نقاطه المهمة من الكتاب.'
        ));
        $this->assertFalse($extractor->isPlaceholderPageText(
            'أَتَوَضَا للصّلاة بالمّاءِ الطهور. أغسل يدي بَعْدَ الخروج من الْحَمّام بالمّاء الطهور.'
        ));
    }

    public function test_broken_cmap_text_layer_is_not_readable(): void
    {
        // The official 2026 editions embed subsetted fonts whose cmap maps every
        // glyph into the Latin-1/symbol blocks, so the text layer decodes to
        // mojibake. It contains letters, so the plain density test accepts it and
        // OCR would be skipped; the extractor must reject it so the real page is
        // OCR'd instead of the learner reading garbage.
        $extractor = app(ContentExtractionService::class);

        // This is a real broken-cmap line from an official edition: the density
        // test alone accepts it (51% "meaningful" glyphs), so only the new
        // foreign-block check rejects it and forces OCR.
        $this->assertFalse($extractor->textIsReadable(
            '∏ ‰U‡‡−‡‡≥ Y¹b(« http://e-learning-moe.edu.ye'
        ));
        $this->assertFalse($extractor->textIsReadable(
            '±∑ ∫vUFð ‰U ≠ ∫wðQ¹ U WIÐU« U¹ü« s Ãd ²Ý« ≠ ÆœbŽ vKŽ Î ô«œ oKD*« ‰uFH*« sŽ'
        ));

        // Genuine Arabic and genuine English must still be readable, so the fix
        // does not force OCR on books that already carry a clean text layer.
        $this->assertTrue($extractor->textIsReadable(
            'أَتَوَضَا للصّلاة بالمّاءِ الطهور. أغسل يدي بَعْدَ الخروج من الْحَمّام بالمّاء الطهور.'
        ));
        $this->assertTrue($extractor->textIsReadable(
            'Unit one: My school. I go to school every morning and I meet my friends there.'
        ));
    }

    public function test_real_page_text_replaces_the_seeded_outline(): void
    {
        $book = $this->outlineBook();

        // A real book's first pages, as OCR would return them.
        $book->pages()->delete();
        TextbookPage::insert([
            $this->page($book->id, 1, "الدرس الأول: نَظَافَة الجسم\nأغسل يدي بالماء الطهور بَعْدَ الخروج من الْحَمّام.\nأحافظ على طهارة جسمي وثيابي."),
            $this->page($book->id, 2, "الدرس الثاني: الصَّلَوَات المَفْرُوضَة\nالصلاة عمود الدين.\nأحافظ على الصلوات في وقتها."),
            $this->page($book->id, 3, "الدرس الثالث: الْوُضُوء\nأتوضأ للصلاة بالماء الطهور.\nأغسل وجهي ويديّ."),
        ]);

        $created = app(ContentExtractionService::class)->draftLessonsFromPages($book);

        $this->assertGreaterThan(0, $created, 'real page text should draft lessons');
        $this->assertSame(0, $this->outlineLessonCount($book->fresh()), 'the outline must be gone');
        $this->assertGreaterThan(0, $book->lessons()->count());
    }

    public function test_authored_lessons_are_never_overwritten(): void
    {
        // Grade-7 English is seeded with authored (non-outline) lessons.
        $book = Textbook::whereHas('subject', fn ($q) => $q->where('name', 'like', '%إنجليز%'))
            ->whereHas('subject.grade', fn ($q) => $q->where('level', 7))
            ->firstOrFail();

        $book->pages()->delete();
        TextbookPage::insert([
            $this->page($book->id, 1, "Unit One: My School\nThis is my school.\nI like my school."),
        ]);

        $before = $book->lessons()->count();
        $created = app(ContentExtractionService::class)->draftLessonsFromPages($book);

        $this->assertSame(0, $created, 'authored lessons must block drafting');
        $this->assertSame($before, $book->lessons()->count());
    }

    public function test_resume_page_returns_the_first_outline_page(): void
    {
        $book = $this->outlineBook();
        // Front page is an outline stub, the tail is already real: extraction
        // must resume at the front, not past the tail.
        $book->pages()->delete();
        TextbookPage::insert([
            $this->page($book->id, 1, 'محتوى هيكلي من فهرس المنهج اليمني الرسمي، يُستكمل لاحقاً.'),
            $this->page($book->id, 2, 'أغسل يدي بالماء الطهور بَعْدَ الخروج من الْحَمّام.'),
            $this->page($book->id, 3, 'الصلاة عمود الدين، أحافظ على الصلوات في وقتها.'),
        ]);
        $book->forceFill(['pages_count' => 3])->save();

        $command = new ImportTextbooks;
        $method = new ReflectionMethod($command, 'resumePage');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke($command, $book->fresh(), app(ContentExtractionService::class)));
    }

    public function test_seeder_does_not_rebuild_the_outline_over_real_content(): void
    {
        $book = $this->outlineBook();
        $book->pages()->delete();
        TextbookPage::insert([
            $this->page($book->id, 1, "الدرس الأول: نَظَافَة الجسم\nأغسل يدي بالماء الطهور."),
        ]);
        app(ContentExtractionService::class)->draftLessonsFromPages($book);
        $realCount = $book->lessons()->count();
        $this->assertGreaterThan(0, $realCount);

        $seeder = new FullCurriculumSeeder;
        $method = new ReflectionMethod($seeder, 'seedBook');
        $method->setAccessible(true);
        $method->invoke($seeder, $book->fresh(), [['title' => 'الوحدة الأولى', 'lessons' => ['الدرس الأول', 'الدرس الثاني']]]);

        $this->assertSame($realCount, $book->lessons()->count(), 'the outline must not be re-seeded');
    }

    /** A seeded book whose lessons are all curriculum-outline placeholders. */
    private function outlineBook(): Textbook
    {
        $grade = Grade::where('level', 7)->firstOrFail();
        $subject = Subject::where('grade_id', $grade->id)
            ->where('name', 'like', '%إسلام%')
            ->firstOrFail();

        return Textbook::where('subject_id', $subject->id)->orderBy('id')->firstOrFail();
    }

    private function outlineLessonCount(Textbook $book): int
    {
        return $book->lessons()
            ->whereHas('sections', fn ($q) => $q->where('content', 'like', '%محتوى هيكلي من فهرس المنهج%'))
            ->count();
    }

    /** @return array<string,mixed> */
    private function page(int $textbookId, int $number, string $text): array
    {
        return [
            'textbook_id' => $textbookId,
            'page_number' => $number,
            'extracted_text' => $text,
            'status' => 'extracted',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
