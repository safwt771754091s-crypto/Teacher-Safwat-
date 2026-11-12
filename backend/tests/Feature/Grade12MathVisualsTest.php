<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\LessonSection;
use App\Models\Subject;
use App\Models\Textbook;
use App\Models\User;
use Database\Seeders\Grade12MathVisualsSeeder;
use Database\Seeders\UsersAndGamesSeeder;
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

    /** Kinds implemented by web/assets/interactive.js (2-D). */
    private const CLIENT_KINDS = [
        'complex-plane', 'function-graph', 'area-under-curve', 'conic',
        'pascal', 'probability-tree', 'unit-circle', 'vector-space', 'number-line',
    ];

    /** Kinds implemented by web/assets/interactive3d.js (3-D). */
    private const CLIENT_3D_KINDS = [
        'solid-3d', 'solid-of-revolution', 'vector-3d', 'surface-3d',
        'atom-3d', 'molecule-3d', 'cell-3d', 'plant-3d', 'earth-3d', 'prism-3d',
    ];

    /** The 3-D kinds the Grade-12 maths book itself is expected to use. */
    private const MATHS_3D_KINDS = [
        'solid-3d', 'solid-of-revolution', 'vector-3d', 'surface-3d',
    ];

    /** Kinds implemented by web/assets/interactive-lessons.js (DOM models). */
    private const CLIENT_LESSON_KINDS = [
        'letter-card', 'counting', 'sequence', 'sort-bins',
        'family-tree', 'sentence-builder', 'clock',
    ];

    /** Kinds implemented by web/assets/interactive-science.js (science/maths). */
    private const CLIENT_SCIENCE_KINDS = [
        'diagram', 'shapes', 'triangle', 'vector-2d', 'states-of-matter',
        'circuit', 'wave', 'bar-chart', 'fractions', 'angles',
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
        $all = array_merge(
            self::CLIENT_KINDS,
            self::CLIENT_3D_KINDS,
            self::CLIENT_LESSON_KINDS,
            self::CLIENT_SCIENCE_KINDS,
        );

        foreach (LessonSection::whereNotNull('visual')->get() as $section) {
            $this->assertContains(
                $section->visual['kind'] ?? null,
                $all,
                "Model kind «{$section->visual['kind']}» is not implemented by the client engines.",
            );
        }
    }

    public function test_the_science_engine_implements_every_kind_it_is_credited_with(): void
    {
        $js = file_get_contents(base_path('../web/assets/interactive-science.js'));

        $this->assertNotFalse($js, 'interactive-science.js should exist.');
        foreach (self::CLIENT_SCIENCE_KINDS as $kind) {
            $this->assertStringContainsString("KINDS['{$kind}']", $js, "Missing the «{$kind}» model.");
        }
    }

    public function test_every_science_subject_has_models_for_all_its_topics(): void
    {
        // Physics, chemistry and biology are seeded as curriculum outlines, but
        // their lesson titles are real topics, so each one can carry a figure.
        foreach (['الفيزياء', 'الكيمياء', 'الأحياء'] as $subject) {
            $missing = Lesson::whereHas('textbook.subject', fn ($q) => $q->where('name', $subject))
                ->whereDoesntHave('sections', fn ($q) => $q->whereNotNull('visual'))
                ->pluck('title')->unique();

            $this->assertSame(
                [],
                $missing->all(),
                "«{$subject}» lessons without a model: ".$missing->implode('، '),
            );
        }
    }

    public function test_the_3d_engine_actually_implements_every_3d_kind_it_is_credited_with(): void
    {
        $js = file_get_contents(base_path('../web/assets/interactive3d.js'));

        $this->assertNotFalse($js, 'interactive3d.js should exist.');
        foreach (self::CLIENT_3D_KINDS as $kind) {
            $this->assertStringContainsString(
                "KINDS['{$kind}']",
                $js,
                "interactive3d.js does not implement the «{$kind}» model.",
            );
        }
        // The 2-D engine must expose a registration hook for the 3-D one.
        $this->assertStringContainsString('register:', file_get_contents(base_path('../web/assets/interactive.js')));
    }

    public function test_the_grade_12_book_teaches_solid_geometry_in_three_dimensions(): void
    {
        $book = $this->mathsBook();
        $kinds = LessonSection::whereIn('lesson_id', Lesson::where('textbook_id', $book->id)->pluck('id'))
            ->whereNotNull('visual')
            ->get()
            ->map(fn ($s) => $s->visual['kind'] ?? null)
            ->filter(fn ($k) => in_array($k, self::CLIENT_3D_KINDS, true));

        $this->assertGreaterThanOrEqual(4, $kinds->count(), 'The book should use several 3-D models.');
        foreach (self::MATHS_3D_KINDS as $kind) {
            $this->assertTrue($kinds->contains($kind), "No lesson uses the «{$kind}» 3-D model.");
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
        $teacher = User::where('role', 'teacher')->firstOrFail();
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
        $teacher = User::where('role', 'teacher')->firstOrFail();
        $lesson = Lesson::where('status', 'published')->firstOrFail();

        $this->actingAs($teacher)->postJson("/api/v1/lessons/{$lesson->id}/sections", [
            'type' => 'example',
            'content' => 'نص',
            'visual' => ['params' => ['n' => 3]], // no kind
        ])->assertStatus(422);
    }

    public function test_demo_accounts_are_restored_when_the_database_is_emptied(): void
    {
        User::query()->delete();

        config(['platform.seed_demo_users' => true]);
        $this->seed(UsersAndGamesSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'student@school.ye', 'role' => 'student']);
        $this->assertDatabaseHas('users', ['email' => 'teacher@school.ye', 'role' => 'teacher']);
    }
}
