<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The authored-lesson models: every lesson with real content gets a figure, and
 * no lesson that is still a curriculum-outline placeholder does.
 */
class AuthoredLessonsModelsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Kinds implemented by web/assets/interactive-lessons.js. */
    private const LESSON_KINDS = [
        'letter-card', 'counting', 'sequence', 'sort-bins',
        'family-tree', 'sentence-builder', 'clock',
    ];

    private function authoredLessonIds()
    {
        return LessonSection::where('content', 'like', '%نتعرف في هذا الدرس على%')
            ->distinct()->pluck('lesson_id');
    }

    public function test_every_authored_lesson_carries_a_model(): void
    {
        $missing = Lesson::where('status', 'published')
            ->whereNotIn('id', $this->authoredLessonIds())
            ->whereDoesntHave('sections', fn ($q) => $q->whereNotNull('visual'))
            ->pluck('title');

        $this->assertSame([], $missing->all(), 'Authored lessons without a model: '.$missing->implode('، '));
    }

    public function test_placeholder_lessons_never_get_an_authored_model(): void
    {
        // A placeholder lesson must not get one of *these* models, which claim to
        // sit beside authored content. Science subjects are different: their
        // lesson titles are real topics, so ScienceModelsSeeder deliberately
        // gives them a «نموذج علمي» figure. This test is about the authored set.
        $leaked = LessonSection::whereNotNull('visual')
            ->where('title', 'like', 'نموذج تفاعلي%')
            ->whereIn('lesson_id', $this->authoredLessonIds())
            ->count();

        $this->assertSame(0, $leaked, 'A curriculum-outline placeholder lesson was given an authored model.');
    }

    public function test_the_lesson_engine_implements_every_kind_it_is_credited_with(): void
    {
        $js = file_get_contents(base_path('../web/assets/interactive-lessons.js'));

        $this->assertNotFalse($js, 'interactive-lessons.js should exist.');
        foreach (self::LESSON_KINDS as $kind) {
            $this->assertStringContainsString("KINDS['{$kind}']", $js, "Missing the «{$kind}» model.");
        }
    }

    public function test_english_topics_are_covered_across_every_grade_that_teaches_them(): void
    {
        // «Telling the time» appears in several grades; each copy should be usable.
        $lessons = Lesson::where('title', 'Telling the time')->where('status', 'published')->get();
        $this->assertGreaterThan(1, $lessons->count());

        foreach ($lessons as $lesson) {
            $this->assertTrue(
                LessonSection::where('lesson_id', $lesson->id)->where('visual->kind', 'clock')->exists(),
                "«Telling the time» (#{$lesson->id}) has no clock model.",
            );
        }
    }

    public function test_the_models_render_for_an_arabic_letter_lesson(): void
    {
        $lesson = Lesson::where('title', 'أحرف: أ - ب - ت')->firstOrFail();
        $section = LessonSection::where('lesson_id', $lesson->id)->whereNotNull('visual')->firstOrFail();

        $this->assertSame('letter-card', $section->visual['kind']);
        $this->assertSame('أ', $section->visual['params']['letter']);
    }
}
