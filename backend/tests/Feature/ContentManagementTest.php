<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Textbook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function owner(): User
    {
        return User::where('email', 'owner@school.ye')->first();
    }

    private function student(): User
    {
        return User::where('email', 'student@school.ye')->first();
    }

    public function test_font_mojibake_is_treated_as_unreadable(): void
    {
        $extractor = app(\App\Services\ContentExtractionService::class);
        $this->assertTrue($extractor->textIsReadable('الوحدة الأولى : تركيب المادة'));
        $this->assertFalse($extractor->textIsReadable('≤ Z?UMLK UOKF« WM?K« ?UCŽ ?‰u?????'));
        $this->assertFalse($extractor->textIsReadable('?‰u?????'));
    }

    public function test_lessons_are_segmented_by_textbook_headings(): void
    {
        $subject = Subject::first();
        $textbook = Textbook::create([
            'subject_id' => $subject->id, 'title' => 'كتاب اختبار', 'term' => 'first',
            'source_type' => 'pdf', 'status' => 'review',
        ]);
        // A contents page (>=3 headings) must not spawn lessons.
        $textbook->pages()->create(['page_number' => 1, 'extracted_text' => "الفهرس\nالدرس الأول : أ\nالدرس الثاني : ب\nالدرس الثالث : ج\n"]);
        $textbook->pages()->create(['page_number' => 2, 'extracted_text' => "الوحدة الأولى\nالدرس الأول : الجمع\nأهداف الدرس\nنتعلم الجمع حتى عشرة\n"]);
        $textbook->pages()->create(['page_number' => 3, 'extracted_text' => "أمثلة على الجمع\n٢ + ٣ = ٥\n"]);
        $textbook->pages()->create(['page_number' => 4, 'extracted_text' => "الدرس الثاني : الطرح\nنتعلم الطرح البسيط\n"]);

        $created = app(\App\Services\ContentExtractionService::class)->draftLessonsFromPages($textbook);
        $this->assertSame(2, $created);

        $lessons = $textbook->lessons()->orderBy('sort_order')->get();
        $this->assertSame('الدرس الأول : الجمع', $lessons[0]->title);
        $this->assertSame(2, $lessons[0]->page_from);
        $this->assertSame(3, $lessons[0]->page_to);
        $this->assertGreaterThan(0, $lessons[0]->sections()->count());
        $this->assertSame('الدرس الثاني : الطرح', $lessons[1]->title);

        // Re-running must not duplicate lessons.
        app(\App\Services\ContentExtractionService::class)->draftLessonsFromPages($textbook);
        $this->assertSame(2, $textbook->lessons()->count());
    }

    public function test_unit_only_book_segments_by_unit(): void
    {
        $subject = Subject::first();
        $textbook = Textbook::create([
            'subject_id' => $subject->id, 'title' => 'رياضيات ابتدائي', 'term' => 'first',
            'source_type' => 'pdf', 'status' => 'review',
        ]);
        $textbook->pages()->create(['page_number' => 4, 'extracted_text' => "الوحدة الأولى\nالأعداد من ١ إلى ١٠\nتمرين: عُدّ ثم اكتب\n"]);
        $textbook->pages()->create(['page_number' => 5, 'extracted_text' => "أمثلة\n١ + ٢ = ٣\n"]);
        $textbook->pages()->create(['page_number' => 6, 'extracted_text' => "الوحدة الثانية\nالجمع والطرح\n"]);
        $textbook->pages()->create(['page_number' => 7, 'extracted_text' => "تدريبات\n٥ - ٢ = ٣\n"]);

        $created = app(\App\Services\ContentExtractionService::class)->draftLessonsFromPages($textbook);
        $this->assertSame(2, $created);

        $lessons = $textbook->lessons()->orderBy('sort_order')->get();
        $this->assertSame(4, $lessons[0]->page_from);
        $this->assertSame(5, $lessons[0]->page_to);
        $this->assertSame(6, $lessons[1]->page_from);
        $this->assertSame(7, $lessons[1]->page_to);
    }

    public function test_textbook_questions_become_unverified_exercise_drafts(): void
    {
        $subject = Subject::first();
        $textbook = Textbook::create([
            'subject_id' => $subject->id, 'title' => 'كتاب تمارين', 'term' => 'first',
            'source_type' => 'pdf', 'status' => 'review',
        ]);
        $textbook->pages()->create(['page_number' => 2, 'extracted_text' => "الدرس الأول : الجمع\nنتعلم الجمع\nتمرين: اجمع ١ + ٢\nأكمل: ٣ + ٤ =\nعبارة عادية ليست سؤالاً\n٥ + ٦\n"]);
        $extractor = app(\App\Services\ContentExtractionService::class);
        $extractor->draftLessonsFromPages($textbook);
        $lesson = $textbook->lessons()->firstOrFail();

        $created = $extractor->draftExercisesFromLesson($lesson);
        $this->assertSame(2, $created);

        $exercises = $lesson->exercises()->orderBy('sort_order')->get();
        $this->assertSame('textbook', $exercises[0]->source);
        $this->assertTrue($exercises[0]->is_draft);
        $this->assertNull($exercises[0]->answer);
        $this->assertNull($exercises[0]->verified_at);
        $this->assertStringContainsString('اجمع', $exercises[0]->question);

        // Re-running replaces drafts instead of duplicating them.
        $extractor->draftExercisesFromLesson($lesson);
        $this->assertSame(2, $lesson->exercises()->count());
    }

    public function test_unverified_ocr_drafts_are_hidden_from_learners(): void
    {
        $subject = Subject::first();
        $textbook = Textbook::create([
            'subject_id' => $subject->id, 'title' => 'كتاب إظهار', 'term' => 'first',
            'source_type' => 'pdf', 'status' => 'published',
        ]);
        $lesson = $textbook->lessons()->create([
            'title' => 'درس', 'page_from' => 1, 'page_to' => 1, 'sort_order' => 1, 'status' => 'published',
        ]);
        $lesson->exercises()->create(['type' => 'short_answer', 'question' => 'مسودة', 'source' => 'textbook', 'is_draft' => true, 'sort_order' => 0]);
        $lesson->exercises()->create(['type' => 'numeric', 'question' => 'مؤلف', 'answer' => '٥', 'source' => 'textbook', 'is_draft' => false, 'sort_order' => 1]);

        $res = $this->actingAs($this->student(), 'sanctum')
            ->getJson("/api/v1/lessons/{$lesson->id}/exercises")
            ->assertOk();

        $questions = array_column($res->json('exercises'), 'question');
        $this->assertNotContains('مسودة', $questions);
        $this->assertContains('مؤلف', $questions);
    }

    public function test_owner_can_create_textbook_and_lesson(): void
    {
        $subject = Subject::first();
        $res = $this->actingAs($this->owner(), 'sanctum')
            ->postJson('/api/v1/textbooks', [
                'subject_id' => $subject->id,
                'title' => 'كتاب تجريبي',
                'term' => 'first',
                'source_type' => 'manual',
            ])->assertStatus(201);

        $bookId = $res->json('textbook.id');
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/textbooks/{$bookId}/lessons", ['title' => 'درس تجريبي'])
            ->assertStatus(201);
    }

    public function test_generate_and_approve_summary_flow(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $res = $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/generate-summary", [])
            ->assertOk();

        $summaryId = $res->json('summary.id');
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/summaries/{$summaryId}/approve", [])
            ->assertOk();
        $this->assertDatabaseHas('summaries', ['id' => $summaryId, 'status' => 'approved']);
    }

    public function test_verify_exercise_records_reviewer(): void
    {
        $lesson = Lesson::where('title', 'الجمع حتى ١٠')->firstOrFail();
        $ex = $lesson->exercises()->first();
        $this->actingAs($this->owner(), 'sanctum')
            ->postJson("/api/v1/exercises/{$ex->id}/verify", ['answer' => '١٠', 'hint' => 'اجمع العددين'])
            ->assertOk();
        $this->assertDatabaseHas('exercises', [
            'id' => $ex->id,
            'verified_by' => $this->owner()->id,
            'answer' => '١٠',
            'hint' => 'اجمع العددين',
        ]);
    }

    public function test_owner_overview_and_ai_usage(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/owner/overview')
            ->assertOk()
            ->assertJsonStructure(['counts' => ['users', 'lessons']]);
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/owner/ai-usage')
            ->assertOk()
            ->assertJsonStructure(['total_requests', 'by_feature']);
    }

    public function test_teacher_can_view_struggling_lessons(): void
    {
        $teacher = User::where('email', 'teacher@school.ye')->first();
        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/struggling-lessons')
            ->assertOk()
            ->assertJsonStructure(['lessons']);
    }

    public function test_owner_can_list_and_review_pages(): void
    {
        $book = Textbook::with('pages')->has('pages')->firstOrFail();
        $page = $book->pages()->firstOrFail();

        $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/textbooks/{$book->id}/pages")
            ->assertOk()
            ->assertJsonStructure(['pages' => [['id', 'page_number', 'status']], 'counts' => ['total', 'raw', 'extracted', 'reviewed']]);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/pages/{$page->id}", [
                'extracted_text' => 'نص مصحّح من الكتاب.',
                'status' => 'reviewed',
            ])->assertOk();

        $this->assertDatabaseHas('textbook_pages', [
            'id' => $page->id,
            'status' => 'reviewed',
            'extracted_text' => 'نص مصحّح من الكتاب.',
        ]);
    }

    public function test_student_cannot_review_pages(): void
    {
        $book = Textbook::has('pages')->firstOrFail();
        $student = User::where('email', 'student@school.ye')->first();
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/textbooks/{$book->id}/pages")
            ->assertStatus(403);
    }
}
