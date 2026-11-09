<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\LessonSection;
use App\Models\Textbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Grade 12 (الصف الثالث الثانوي) is the flagship year: every lesson across all
 * thirteen books must be usable on its own — a real explanation and a figure to
 * manipulate.
 *
 * Two contracts are protected here:
 *  1. No lesson in the year still shows the outline placeholder
 *     «نتعرف في هذا الدرس على…», and every lesson carries a model.
 *  2. Our explanation is never presented as the book's own words: sections we
 *     wrote are marked `is_from_textbook = false`.
 */
class Grade12CoverageTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** The placeholder the curriculum was seeded with. */
    private const PLACEHOLDER = 'نتعرف في هذا الدرس على';

    private function books(): Collection
    {
        // A Textbook carries no grade_id; the grade is reached through its subject.
        $grade = Grade::where('level', 12)->firstOrFail();

        return Textbook::whereHas('subject', fn ($q) => $q->where('grade_id', $grade->id))
            ->where('title', 'like', '%الثالث الثانوي%')
            ->get();
    }

    public function test_the_year_has_all_thirteen_books(): void
    {
        $this->assertGreaterThanOrEqual(
            13,
            $this->books()->count(),
            'Grade 12 should carry its full set of books.',
        );
    }

    public function test_no_grade_12_lesson_is_left_as_an_outline(): void
    {
        $stale = [];

        foreach ($this->books() as $book) {
            foreach (Lesson::where('textbook_id', $book->id)->get() as $lesson) {
                $isOutline = LessonSection::where('lesson_id', $lesson->id)
                    ->where('content', 'like', '%'.self::PLACEHOLDER.'%')
                    ->exists();

                if ($isOutline) {
                    $stale[] = "{$book->title} / {$lesson->title}";
                }
            }
        }

        $this->assertSame([], $stale, "Grade 12 lessons still on the outline placeholder:\n".implode("\n", $stale));
    }

    public function test_every_grade_12_lesson_carries_a_model(): void
    {
        $missing = [];

        foreach ($this->books() as $book) {
            foreach (Lesson::where('textbook_id', $book->id)->get() as $lesson) {
                if (! LessonSection::where('lesson_id', $lesson->id)->whereNotNull('visual')->exists()) {
                    $missing[] = "{$book->title} / {$lesson->title}";
                }
            }
        }

        $this->assertSame([], $missing, "Grade 12 lessons without a model:\n".implode("\n", $missing));
    }

    public function test_our_explanations_are_never_marked_as_book_text(): void
    {
        foreach ($this->books() as $book) {
            foreach (Lesson::where('textbook_id', $book->id)->get() as $lesson) {
                $written = LessonSection::where('lesson_id', $lesson->id)
                    ->where('content', 'like', '%'.self::PLACEHOLDER.'%')
                    ->where('is_from_textbook', true)
                    ->exists();

                $this->assertFalse(
                    $written,
                    "Lesson «{$lesson->title}» marks outline text as coming from the book.",
                );
            }
        }
    }

    public function test_grade_12_lesson_api_returns_content_and_models(): void
    {
        $book = $this->books()->first(fn ($b) => str_contains($b->title, 'الفيزياء'));
        $lesson = Lesson::where('textbook_id', $book->id)->where('title', 'الكوانتم')->firstOrFail();

        $response = $this->getJson("/api/v1/lessons/{$lesson->id}");

        $response->assertOk();
        $sections = collect($response->json('lesson.sections'));

        $this->assertTrue(
            $sections->contains(fn ($s) => ! empty($s['visual']['kind'])),
            'The lesson API should carry at least one model.',
        );
        $this->assertTrue(
            $sections->contains(fn ($s) => str_contains((string) ($s['content'] ?? ''), 'الكمّات')),
            'The lesson API should carry the written explanation.',
        );
    }
}
