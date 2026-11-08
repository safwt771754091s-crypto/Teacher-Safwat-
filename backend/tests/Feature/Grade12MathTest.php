<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\GameActivity;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Textbook;
use Database\Seeders\Grade12MathSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the first fully authored secondary book on the platform:
 * الرياضيات — الصف الثالث الثانوي (القسم العلمي).
 *
 * These assertions protect the contract that makes the book trustworthy:
 * every published lesson carries real sections, an approved summary and a
 * graded exercise set with worked solution steps, the structural placeholders
 * are archived (not shown to learners), and the topic game ships a real,
 * answerable question set.
 */
class Grade12MathTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function book(): Textbook
    {
        $subject = Subject::whereHas('grade', fn ($q) => $q->where('level', 12))
            ->where('name', 'الرياضيات')
            ->firstOrFail();

        return $subject->textbooks()->where('term', 'first')->firstOrFail();
    }

    public function test_grade12_math_book_is_the_scientific_branch_and_published(): void
    {
        $book = $this->book();
        $this->assertSame('scientific', $book->branch);
        $this->assertSame('published', $book->status);
        $this->assertNotNull($book->source_url);
    }

    public function test_the_book_has_a_full_set_of_authored_lessons(): void
    {
        $book = $this->book();
        $published = $book->lessons()->where('status', 'published')->count();
        $this->assertGreaterThanOrEqual(20, $published, 'the authored book should cover the whole syllabus');
    }

    public function test_no_structural_placeholder_lesson_is_shown_to_learners(): void
    {
        $book = $this->book();
        $visible = Lesson::where('textbook_id', $book->id)->where('status', 'published')->with('sections')->get();
        foreach ($visible as $lesson) {
            foreach ($lesson->sections as $section) {
                $this->assertStringNotContainsString('محتوى هيكلي من فهرس المنهج', (string) $section->content,
                    "lesson {$lesson->title} still exposes structural placeholder content");
            }
        }
    }

    public function test_every_published_lesson_has_sections_summary_and_exercises(): void
    {
        $book = $this->book();
        $lessons = Lesson::where('textbook_id', $book->id)->where('status', 'published')->withCount(['sections', 'exercises'])->get();
        $this->assertNotEmpty($lessons);

        foreach ($lessons as $lesson) {
            $this->assertGreaterThanOrEqual(3, $lesson->sections_count, "lesson {$lesson->title} has too few sections");
            $this->assertGreaterThanOrEqual(1, $lesson->exercises_count, "lesson {$lesson->title} has no exercises");
            $this->assertNotNull($lesson->approvedSummary, "lesson {$lesson->title} has no approved summary");
        }
    }

    public function test_every_exercise_carries_solution_steps_and_a_source_page(): void
    {
        $book = $this->book();
        $exercises = Exercise::whereHas('lesson', fn ($q) => $q->where('textbook_id', $book->id))->get();
        $this->assertGreaterThanOrEqual(60, $exercises->count());

        foreach ($exercises as $exercise) {
            $this->assertIsArray($exercise->solution_steps, "exercise {$exercise->id} has no solution steps");
            $this->assertNotEmpty($exercise->solution_steps, "exercise {$exercise->id} has empty solution steps");
            $this->assertNotNull($exercise->source_page, "exercise {$exercise->id} has no source page");
            $this->assertNotNull($exercise->answer, "exercise {$exercise->id} has no reference answer");
        }
    }

    public function test_multiple_choice_exercises_always_have_exactly_one_correct_option(): void
    {
        $book = $this->book();
        $mcqs = Exercise::whereHas('lesson', fn ($q) => $q->where('textbook_id', $book->id))
            ->where('type', 'mcq')->with('options')->get();
        $this->assertNotEmpty($mcqs);

        foreach ($mcqs as $mcq) {
            $correct = $mcq->options->where('is_correct', true)->count();
            $this->assertSame(1, $correct, "mcq {$mcq->id} must have exactly one correct option");
            $this->assertGreaterThanOrEqual(2, $mcq->options->count());
        }
    }

    public function test_the_lesson_api_exposes_sections_with_source_pages(): void
    {
        $lesson = Lesson::where('textbook_id', $this->book()->id)
            ->where('title', 'القطع المكافئ')->firstOrFail();

        $res = $this->getJson("/api/v1/lessons/{$lesson->id}")->assertOk();
        $this->assertNotEmpty($res->json('lesson.sections'));
        foreach ($res->json('lesson.sections') as $section) {
            $this->assertNotNull($section['source_page']);
        }
    }

    public function test_the_grade12_math_game_ships_a_real_question_set(): void
    {
        $subject = Subject::whereHas('grade', fn ($q) => $q->where('level', 12))
            ->where('name', 'الرياضيات')->firstOrFail();

        $game = GameActivity::where('subject_id', $subject->id)->firstOrFail();
        $questions = $game->config['questions'] ?? [];
        $this->assertGreaterThanOrEqual(10, count($questions));

        foreach ($questions as $q) {
            $this->assertArrayHasKey('q', $q);
            $this->assertArrayHasKey('options', $q);
            $this->assertArrayHasKey('answer', $q);
            $this->assertContains($q['answer'], $q['options'], 'the correct answer must be one of the options');
        }
    }

    public function test_seeding_is_idempotent_and_does_not_duplicate_lessons(): void
    {
        $book = $this->book();
        $before = Lesson::where('textbook_id', $book->id)->count();

        $this->seed(Grade12MathSeeder::class);

        $this->assertSame($before, Lesson::where('textbook_id', $book->id)->count());
    }

    public function test_archived_draft_lessons_are_hidden_from_the_lessons_api(): void
    {
        $book = $this->book();

        // Learners see only published lessons.
        $public = $this->getJson("/api/v1/textbooks/{$book->id}/lessons")->assertOk();
        foreach ($public->json('lessons') as $lesson) {
            $this->assertSame('published', $lesson['status']);
        }
        $this->assertSame(
            Lesson::where('textbook_id', $book->id)->where('status', 'published')->count(),
            count($public->json('lessons')),
        );

        // Admins can still see every row (including archived drafts).
        $all = $this->getJson("/api/v1/textbooks/{$book->id}/lessons?all=1")->assertOk();
        $this->assertGreaterThan(count($public->json('lessons')), count($all->json('lessons')));
    }

    public function test_grade_twelve_is_reachable_through_the_public_api(): void
    {
        $grade = Grade::where('level', 12)->firstOrFail();
        $res = $this->getJson("/api/v1/grades/{$grade->id}/subjects")->assertOk();
        $this->assertTrue(collect($res->json('subjects'))->pluck('name')->contains('الرياضيات'));
    }
}
