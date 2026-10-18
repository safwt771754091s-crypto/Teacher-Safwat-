<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_grades_are_public_and_include_all_stages(): void
    {
        $res = $this->getJson('/api/v1/grades')->assertOk();
        $this->assertCount(12, $res->json('grades'));
    }

    public function test_grade_one_has_yemeni_subjects(): void
    {
        $grade = Grade::where('level', 1)->first();
        $res = $this->getJson("/api/v1/grades/{$grade->id}/subjects")->assertOk();
        $names = collect($res->json('subjects'))->pluck('name');
        $this->assertTrue($names->contains('الرياضيات'));
        $this->assertTrue($names->contains('لغتي الجميلة (العربية)'));
        $this->assertTrue($names->contains('العلوم'));
    }

    public function test_lesson_exposes_sections_with_source_pages(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $res = $this->getJson("/api/v1/lessons/{$lesson->id}")->assertOk();
        foreach ($res->json('lesson.sections') as $s) {
            $this->assertNotNull($s['source_page']);
        }
    }

    public function test_textbooks_expose_authority_and_can_be_filtered(): void
    {
        $grade = Grade::where('level', 1)->first();
        $subject = $grade->subjects()->first();
        $aden = $subject->textbooks()->create([
            'title' => 'كتاب منهج عدن', 'term' => 'first', 'source_type' => 'pdf',
            'authority' => 'aden', 'status' => 'published',
        ]);
        $sanaa = $subject->textbooks()->create([
            'title' => 'كتاب منهج صنعاء', 'term' => 'first', 'source_type' => 'pdf',
            'authority' => 'sanaa', 'status' => 'published',
        ]);
        // Catalogue-only books (no lessons) are hidden from learners, so give
        // each a lesson to make it a real, listed book.
        foreach ([$aden, $sanaa] as $book) {
            $book->lessons()->create(['title' => 'درس', 'page_from' => 1, 'page_to' => 2, 'status' => 'published']);
        }

        $all = $this->getJson("/api/v1/subjects/{$subject->id}/textbooks")->assertOk();
        $this->assertArrayHasKey('authorities', $all->json());

        $aden = $this->getJson("/api/v1/subjects/{$subject->id}/textbooks?authority=aden")->assertOk();
        $titles = collect($aden->json('textbooks'))->pluck('title');
        $this->assertTrue($titles->contains('كتاب منهج عدن'));
        $this->assertFalse($titles->contains('كتاب منهج صنعاء'));

        // A catalogue-only book (no lessons) must not be listed to learners, but
        // stays available to admins via ?all=1.
        $empty = $subject->textbooks()->create([
            'title' => 'كتاب بلا دروس', 'term' => 'first', 'source_type' => 'manual', 'status' => 'published',
        ]);
        $listed = collect($this->getJson("/api/v1/subjects/{$subject->id}/textbooks")->json('textbooks'))->pluck('id');
        $this->assertFalse($listed->contains($empty->id));
        $allListed = collect($this->getJson("/api/v1/subjects/{$subject->id}/textbooks?all=1")->json('textbooks'))->pluck('id');
        $this->assertTrue($allListed->contains($empty->id));
    }

    public function test_page_source_is_retrievable(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $this->getJson("/api/v1/lessons/{$lesson->id}/pages/17")
            ->assertOk();
    }

    public function test_every_grade_has_subjects_and_published_lessons(): void
    {
        foreach (Grade::all() as $grade) {
            $subjects = $grade->subjects()->withCount('textbooks')->get();
            $this->assertGreaterThanOrEqual(2, $subjects->count(), "grade {$grade->level} has too few subjects");

            $lessons = Lesson::whereHas('textbook.subject', fn ($q) => $q->where('grade_id', $grade->id))
                ->where('status', 'published')
                ->count();
            $this->assertGreaterThanOrEqual(4, $lessons, "grade {$grade->level} has too few lessons");
        }
    }

    public function test_full_curriculum_is_loaded_from_the_reference_file(): void
    {
        // The official reference covers grades 1–12 with 1194 lessons.
        $this->assertGreaterThanOrEqual(1000, Lesson::where('status', 'published')->count());
        $this->assertGreaterThanOrEqual(100, Subject::count());

        $grade12 = Grade::where('level', 12)->firstOrFail();
        $names = $grade12->subjects()->pluck('name');
        $this->assertTrue($names->contains('الفلسفة والمنطق'));
        $this->assertTrue($names->contains('علم النفس والاجتماع'));

        $grade5 = Grade::where('level', 5)->firstOrFail();
        $this->assertTrue($grade5->subjects()->pluck('name')->contains('اللغة الإنجليزية'));
    }

    public function test_secondary_grade_subjects_are_browsable(): void
    {
        $grade10 = Grade::where('level', 10)->firstOrFail();
        $res = $this->getJson("/api/v1/grades/{$grade10->id}/subjects")->assertOk();
        $names = collect($res->json('subjects'))->pluck('name');
        $this->assertTrue($names->contains('الفيزياء'));
        $this->assertTrue($names->contains('الكيمياء'));

        $physics = $grade10->subjects()->where('name', 'الفيزياء')->firstOrFail();
        $book = $physics->textbooks()->firstOrFail();
        $lessons = $this->getJson("/api/v1/textbooks/{$book->id}/lessons")->assertOk();
        $this->assertNotEmpty($lessons->json('lessons'));
    }

    public function test_every_subject_has_a_playable_game(): void
    {
        $this->assertGreaterThanOrEqual(100, \App\Models\GameActivity::count());

        // Every subject in the curriculum must ship at least one game with a
        // real question set, so learners get interactive games in all subjects.
        foreach (Subject::all() as $subject) {
            $game = \App\Models\GameActivity::where('subject_id', $subject->id)->first();
            $this->assertNotNull($game, "no game for subject {$subject->name}");
            $questions = $game->config['questions'] ?? [];
            $this->assertGreaterThanOrEqual(2, count($questions), "game for {$subject->name} has too few questions");
            foreach ($questions as $q) {
                $this->assertArrayHasKey('q', $q);
                $this->assertContains($q['answer'], $q['options'], "answer missing from options for {$subject->name}");
            }
        }
    }

    public function test_english_lessons_are_explained_in_arabic_and_read_in_english(): void
    {
        $subject = Subject::where('name', 'اللغة الإنجليزية')->whereHas('textbooks.lessons')->firstOrFail();
        $lesson = Lesson::whereHas('textbook', fn ($q) => $q->where('subject_id', $subject->id))
            ->where('title', 'Greetings')->firstOrFail();

        $explanation = app(\App\Services\AiTeacherService::class)->explain($lesson)['explanation'];
        $joined = implode("\n", $explanation);

        // Arabic explanation present, English word read, and its translation shown.
        $this->assertMatchesRegularExpression('/[\p{Arabic}]/u', $joined);
        $this->assertStringContainsString('Greetings', $joined);
        $this->assertStringContainsString('—', $joined);

        // The translation exercise accepts the Arabic meaning of the English word.
        $ex = $lesson->exercises()->where('type', 'short_answer')->firstOrFail();
        $this->assertMatchesRegularExpression('/[\p{Arabic}]/u', (string) $ex->answer);
    }

    public function test_english_vocabulary_game_pairs_english_word_with_arabic_meaning(): void
    {
        $subject = Subject::where('name', 'اللغة الإنجليزية')->firstOrFail();
        $game = \App\Models\GameActivity::where('subject_id', $subject->id)->firstOrFail();
        $q = $game->config['questions'][0];

        $this->assertStringContainsString('الإنجليزية', $q['q']);
        $this->assertMatchesRegularExpression('/[\p{Arabic}]/u', $q['answer']);
    }

    public function test_whole_textbook_is_downloadable_as_html_with_exercises(): void
    {
        $book = \App\Models\Textbook::whereHas('lessons.exercises')->firstOrFail();

        $res = $this->get("/api/v1/textbooks/{$book->id}/download?format=html")->assertOk();
        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));

        $html = $res->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        // Every lesson of the book appears, with its exercises and answers.
        $this->assertSame($book->lessons()->count(), substr_count($html, 'class="lesson"'));
        $this->assertStringContainsString('class="exercise"', $html);
        $this->assertStringContainsString('الجواب:', $html);
    }

    public function test_whole_grade_is_downloadable_as_html(): void
    {
        $grade = Grade::where('level', 1)->firstOrFail();

        $res = $this->get("/api/v1/grades/{$grade->id}/download?format=html")->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString($grade->name, $html);
        $this->assertStringContainsString('المنهج اليمني', $html);
        $this->assertGreaterThan(1, substr_count($html, 'class="lesson"'));
    }

    public function test_whole_textbook_pdf_is_generated(): void
    {
        $book = \App\Models\Textbook::whereHas('lessons')->firstOrFail();

        $res = $this->get("/api/v1/textbooks/{$book->id}/download?format=pdf")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_download_includes_the_actual_textbook_pages(): void
    {
        $book = \App\Models\Textbook::whereHas('pages', fn ($q) => $q->whereNotNull('extracted_text'))->firstOrFail();

        $html = $this->get("/api/v1/textbooks/{$book->id}/download?format=html")->assertOk()->getContent();
        $this->assertStringContainsString('نصّ الكتاب', $html);
        $this->assertStringContainsString('class="page"', $html);

        // `pages=0` returns the explanation/exercises only.
        $without = $this->get("/api/v1/textbooks/{$book->id}/download?format=html&pages=0")->assertOk()->getContent();
        $this->assertStringNotContainsString('class="page"', $without);
    }
}
