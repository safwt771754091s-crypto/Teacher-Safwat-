<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonSection;
use Database\Seeders\InteractiveModelsCoverageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The interactive-model coverage seeder must leave no lesson without a model,
 * must use real three-dimensional kinds for spatial topics, and must only ever
 * emit kinds the front-end engine actually registers.
 */
class InteractiveModelsCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_every_lesson_gets_at_least_one_interactive_model(): void
    {
        $total = Lesson::count();
        $this->assertGreaterThan(0, $total, 'the curriculum should be seeded');

        $missing = Lesson::query()
            ->whereDoesntHave('sections', fn ($q) => $q->whereNotNull('visual'))
            ->count();

        $this->assertSame(0, $missing, "{$missing} lesson(s) have no interactive model");
    }

    public function test_spatial_topics_get_a_three_dimensional_model(): void
    {
        foreach (['الذرة', 'الخلية وتركيبها', 'الشمس والقمر'] as $title) {
            $lesson = Lesson::where('title', $title)->first();
            $this->assertNotNull($lesson, "lesson «{$title}» should exist");

            $kinds = $this->visualKinds($lesson);
            $this->assertNotEmpty(
                array_filter($kinds, fn ($k) => str_ends_with($k, '-3d')),
                "lesson «{$title}» should carry a 3-D model, got: ".implode(', ', $kinds),
            );
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $before = LessonSection::count();

        $this->seed(InteractiveModelsCoverageSeeder::class);

        $this->assertSame($before, LessonSection::count(), 're-running the seeder must not add or remove sections');
    }

    public function test_every_seeded_kind_is_registered_by_the_frontend_engine(): void
    {
        $registered = $this->registeredKinds();

        $used = LessonSection::query()
            ->whereNotNull('visual')
            ->get()
            ->map(fn ($s) => is_array($s->visual) ? ($s->visual['kind'] ?? null) : null)
            ->filter()
            ->unique()
            ->values();

        $this->assertNotEmpty($used, 'some model kinds should be in use');

        foreach ($used as $kind) {
            $this->assertContains(
                $kind,
                $registered,
                "the engine does not register the model kind «{$kind}»",
            );
        }
    }

    /** @return array<int, string> */
    private function visualKinds(Lesson $lesson): array
    {
        return $lesson->sections()
            ->whereNotNull('visual')
            ->get()
            ->map(fn ($s) => is_array($s->visual) ? ($s->visual['kind'] ?? '') : '')
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function registeredKinds(): array
    {
        $kinds = [];
        foreach (glob(base_path('../web/assets/interactive*.js')) as $file) {
            if (preg_match_all("/KINDS\['([a-z0-9-]+)'\]/", (string) file_get_contents($file), $m)) {
                $kinds = array_merge($kinds, $m[1]);
            }
        }

        return array_values(array_unique($kinds));
    }
}
