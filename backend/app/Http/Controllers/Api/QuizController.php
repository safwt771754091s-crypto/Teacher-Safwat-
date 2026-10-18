<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuizBankService;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function __construct(private QuizBankService $quiz)
    {
    }

    /**
     * Public curriculum self-test: multiple-choice questions drawn from the
     * stored curriculum, each with the lesson/unit/page it came from. The
     * catalogue is not secret, so this endpoint needs no authentication — it
     * powers the standalone "اختبر معلوماتك" page.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'grade_id' => ['nullable', 'integer', 'exists:grades,id'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->quiz->build(
            isset($data['grade_id']) ? (int) $data['grade_id'] : null,
            isset($data['subject_id']) ? (int) $data['subject_id'] : null,
            isset($data['limit']) ? (int) $data['limit'] : null,
        );

        return response()->json([
            'grade' => $result['grade'] ? [
                'id' => $result['grade']->id,
                'name' => $result['grade']->name,
                'level' => $result['grade']->level,
            ] : null,
            'subject' => $result['subject'] ? [
                'id' => $result['subject']->id,
                'name' => $result['subject']->name,
            ] : null,
            'questions' => $result['questions'],
        ]);
    }
}
