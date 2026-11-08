<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\LessonSection;
use App\Models\Subject;
use App\Models\Textbook;
use Database\Seeders\Grade12MathVisualsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the interactive models attached to the Grade 12 maths book.
 *
 * A lesson used to be text only; these tests protect the contract that makes the
 * models usable: every published maths lesson carries at least one manipulable
 * model, the model's `kind` is one the client engine can actually draw, the JSON
 * round-trips through the API intact, and the models stay grounded in the book
 * (they are marked as not-from-textbook so they never masquerade as book text).
 */
class Grade12MathVisualsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** Kinds that web/assets/interactive.js implements. */
    private const CLIENT_KINDS = [
        'complex-plane', 'function-graph', 'area-under-curve', 'conic',
        'pascal', 'probability-tree', 'unit-circle', 'vector-space', 'number-line',
    ];

    private function mathsBook(): Textbook
    {
        $grade = Grade::where('level', 12)->firstOrFail();
        $subject = Subject::where('grade_id', $grade->id)->where('name', 'الرياضيات')->firstOrFail();

        return Textbook::where('subject_id', $subject->id)->where('branch', 'scientific')->firstOrFail();
    }

    public function test_every_published_lesson_carries_an_interactive_model(): void
    {
        $book = $this->mathsBook();
        $lessons = Lesson::where('textbook_id', $book->id)->where('status', 'published')->get();

        $this->assertGreaterThanOrEqual(20, $lessons->count(), 'Expected the authored Grade 12 maths lessons.');

        foreach ($lessons as $lesson) {
            $models = LessonSection::where('lesson_id', $lesson->id)->whereNotNull('visual')->get();
            $this->assertGreaterThanOrEqual(
                1,
                $models->count(),
                "Lesson «{$lesson->title}» has no interactive model.",
            );
        }
    }

    public function test_model_kinds_are_supported_by_the_client_engine(): void
    {
        foreach (LessonSection::whereNotNull('visual')->get() as $section) {
            $this->assertContains(
                $section->visual['kind'] ?? null,
                self::CLIENT_KINDS,
                "Model kind «{$section->visual['kind']}» is not implemented in interactive.js.",
            );
        }
    }

    public function test_visual_payload_round_trips_through_the_api(): void
    {
        $book = $this->mathsBook();
        $lesson = Lesson::where('textbook_id', $book->id)->where('status', 'published')->firstOrFail();

        $res = $this->getJson("/api/v1/lessons/{$lesson->id}")->assertOk();
        $sections = $res->json('lesson.sections');
        $this->assertIsArray($sections);

        $visual = collect($sections)->firstWhere(fn ($s) => ! empty($s['visual']));
        $this->assertNotNull($visual, 'The lesson payload should expose at least one visual model.');
        $this->assertIsArray($visual['visual'], 'visual must decode to an object, not a string.');
        $this->assertNotEmpty($visual['visual']['kind']);
    }

    public function test_models_are_marked_as_not_from_the_textbook(): void
    {
        // Models are teaching aids the platform authored; they must never be
        // presented as verbatim book text, which the UI keys off is_from_textbook.
        foreach (LessonSection::whereNotNull('visual')->get() as $section) {
            $this->assertFalse((bool) $section->is_from_textbook);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $before = LessonSection::whereNotNull('visual')->count();
        $this->seed(Grade12MathVisualsSeeder::class);
        $after = LessonSection::whereNotNull('visual')->count();

        $this->assertSame($before, $after, 'Re-running the visuals seeder must not duplicate models.');
    }

    public function test_a_teacher_can_attach_a_model_to_a_section(): void
    {
        $teacher = \App\Models\User::where('role', 'teacher')->firstOrFail();
        $lesson = Lesson::where('status', 'published')->firstOrFail();

        $res = $this->actingAs($teacher)->postJson("/api/v1/lessons/{$lesson->id}/sections", [
            'type' => 'example',
            'title' => 'نموذج من المعلم',
            'content' => 'جرّب بنفسك',
            'visual' => ['kind' => 'pascal', 'params' => ['n' => 3], 'prompt' => 'راقب المعاملات'],
        ])->assertCreated();

        $this->assertSame('pascal', $res->json('section.visual.kind'));
        $this->assertSame(3, $res->json('section.visual.params.n'));
        // Teacher-authored content is not verbatim book text.
        $this->assertFalse((bool) $res->json('section.is_from_textbook'));
    }

    public function test_a_section_with_an_unknown_model_shape_is_rejected(): void
    {
        $teacher = \App\Models\User::where('role', 'teacher')->firstOrFail();
        $lesson = Lesson::where('status', 'published')->firstOrFail();

        $this->actingAs($teacher)->postJson("/api/v1/lessons/{$lesson->id}/sections", [
            'type' => 'example',
            'content' => 'نص',
            'visual' => ['params' => ['n' => 3]], // no kind
        ])->assertStatus(422);
    }
}
