<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizBankTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_quiz_is_public_and_returns_questions_with_evidence(): void
    {
        $grade = Grade::where('level', 1)->firstOrFail();
        $res = $this->getJson("/api/v1/quiz?grade_id={$grade->id}&limit=5")->assertOk();

        $questions = $res->json('questions');
        $this->assertNotEmpty($questions);
        $this->assertLessThanOrEqual(5, count($questions));

        foreach ($questions as $q) {
            $this->assertSame('mcq', $q['type']);
            $this->assertNotSame('', $q['prompt']);
            $this->assertNotEmpty($q['evidence']);
            // Every question cites the curriculum row it came from.
            $this->assertArrayHasKey('lesson_id', $q['source']);
            $this->assertArrayHasKey('page', $q['source']);
        }
    }

    public function test_every_question_has_exactly_one_correct_option(): void
    {
        $grade = Grade::where('level', 1)->firstOrFail();
        $res = $this->getJson("/api/v1/quiz?grade_id={$grade->id}&limit=20")->assertOk();

        $this->assertNotEmpty($res->json('questions'));
        foreach ($res->json('questions') as $q) {
            $this->assertGreaterThanOrEqual(2, count($q['options']), 'A question needs at least two options.');
            $correct = array_values(array_filter($q['options'], fn ($o) => $o['is_correct'] === true));
            $this->assertCount(1, $correct, 'Exactly one option must be keyed as correct.');
            // The keyed option must equal the stated answer.
            $this->assertSame($q['answer'], $correct[0]['text']);
        }
    }

    public function test_quiz_can_be_scoped_to_a_subject(): void
    {
        $grade = Grade::where('level', 1)->firstOrFail();
        $subject = Subject::where('grade_id', $grade->id)->where('name', 'الرياضيات')->firstOrFail();

        $res = $this->getJson("/api/v1/quiz?grade_id={$grade->id}&subject_id={$subject->id}&limit=10")->assertOk();
        $this->assertSame('الرياضيات', $res->json('subject.name'));

        foreach ($res->json('questions') as $q) {
            $this->assertSame('الرياضيات', $q['source']['subject']);
        }
    }

    public function test_quiz_rejects_an_unknown_grade(): void
    {
        $this->getJson('/api/v1/quiz?grade_id=999999')->assertStatus(422);
    }
}
