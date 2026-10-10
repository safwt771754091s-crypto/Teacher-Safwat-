<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Summary;
use App\Models\Textbook;
use App\Models\TextbookPage;
use App\Models\User;
use App\Services\AiTeacherService;
use App\Services\ContentExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TeacherController extends Controller
{
    public function __construct(
        private ContentExtractionService $extractor,
        private AiTeacherService $ai,
    ) {
    }

    /**
     * Create a textbook record, then upload a PDF or page images to extract from.
     */
    public function createTextbook(Request $request)
    {
        $data = $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
            'title' => ['required', 'string', 'max:200'],
            'term' => ['required', 'in:first,second'],
            'edition' => ['nullable', 'string', 'max:60'],
            'source_type' => ['required', 'in:pdf,images,manual'],
        ]);

        $textbook = Textbook::create($data + ['status' => 'draft']);
        return response()->json(['textbook' => $textbook], 201);
    }

    public function uploadPdf(Request $request, Textbook $textbook)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:51200']]);

        $path = $request->file('file')->store('textbooks', config('platform.storage.textbook_disk', 'public'));
        $textbook->update([
            'file_path' => $path,
            'source_type' => 'pdf',
        ]);

        $pages = $this->extractor->extractPdf(Storage::disk(config('platform.storage.textbook_disk', 'public'))->path($path), $textbook);
        $textbook->update(['source_type' => 'pdf', 'pages_count' => count($pages)]);

        return response()->json([
            'textbook' => $textbook->fresh(),
            'pages_extracted' => count($pages),
            'needs_review' => true,
        ]);
    }

    public function uploadPage(Request $request, Textbook $textbook)
    {
        $data = $request->validate([
            'page_number' => ['required', 'integer', 'min:1'],
            'image' => ['required', 'file', 'image', 'max:20480'],
            'text' => ['nullable', 'string'],
        ]);

        $path = $request->file('image')->store('textbook-pages', config('platform.storage.textbook_disk', 'public'));
        $page = $this->extractor->storeImagePage($textbook, $data['page_number'], $path, $data['text'] ?? null);

        return response()->json(['page' => $page], 201);
    }

    /**
     * List a textbook's pages so reviewers can inspect and correct extraction.
     */
    public function pages(Textbook $textbook)
    {
        $pages = $textbook->pages()
            ->orderBy('page_number')
            ->get(['id', 'page_number', 'extracted_text', 'image_path', 'status', 'review_note']);

        return response()->json([
            'textbook' => ['id' => $textbook->id, 'title' => $textbook->title, 'status' => $textbook->status],
            'pages' => $pages,
            'counts' => [
                'total' => $pages->count(),
                'raw' => $pages->where('status', 'raw')->count(),
                'extracted' => $pages->where('status', 'extracted')->count(),
                'reviewed' => $pages->where('status', 'reviewed')->count(),
            ],
        ]);
    }

    public function updatePage(Request $request, TextbookPage $page)
    {
        $data = $request->validate([
            'extracted_text' => ['required', 'string'],
            'status' => ['nullable', 'in:raw,extracted,reviewed'],
            'review_note' => ['nullable', 'string'],
        ]);
        $page->update($data + ['status' => $data['status'] ?? 'reviewed']);
        return response()->json(['page' => $page]);
    }

    public function draftLessons(Textbook $textbook)
    {
        $count = $this->extractor->draftLessonsFromPages($textbook);
        return response()->json(['lessons_created' => $count]);
    }

    public function publishTextbook(Textbook $textbook)
    {
        $textbook->update(['status' => 'published']);
        return response()->json(['textbook' => $textbook]);
    }

    public function createLesson(Request $request, Textbook $textbook)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'page_from' => ['nullable', 'integer'],
            'page_to' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $lesson = $textbook->lessons()->create($data + ['status' => 'draft']);
        return response()->json(['lesson' => $lesson], 201);
    }

    public function createSection(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'type' => ['required', 'in:heading,point,definition,example,note,vocab'],
            'title' => ['nullable', 'string', 'max:200'],
            'content' => ['required', 'string'],
            'source_page' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $section = $lesson->sections()->create($data + ['is_from_textbook' => true]);
        return response()->json(['section' => $section], 201);
    }

    public function createExercise(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'type' => ['required', 'in:mcq,true_false,short_answer,step_by_step,numeric,ordering'],
            'question' => ['required', 'string'],
            'answer' => ['nullable', 'string'],
            'solution_steps' => ['nullable', 'array'],
            'hint' => ['nullable', 'string'],
            'source_page' => ['nullable', 'integer'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'options' => ['nullable', 'array'],
            'options.*.text' => ['required_with:options', 'string'],
            'options.*.label' => ['nullable', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
        ]);

        $exercise = $lesson->exercises()->create([
            'type' => $data['type'],
            'question' => $data['question'],
            'answer' => $data['answer'] ?? null,
            'solution_steps' => $data['solution_steps'] ?? null,
            'hint' => $data['hint'] ?? null,
            'source_page' => $data['source_page'] ?? null,
            'difficulty' => $data['difficulty'] ?? 'easy',
            'source' => 'teacher',
        ]);

        foreach ($data['options'] ?? [] as $i => $opt) {
            $exercise->options()->create([
                'label' => $opt['label'] ?? null,
                'text' => $opt['text'],
                'is_correct' => (bool) ($opt['is_correct'] ?? false),
                'sort_order' => $i,
            ]);
        }

        return response()->json(['exercise' => $exercise->load('options')], 201);
    }

    /**
     * Verify an exercise (a human confirms it matches the textbook).
     *
     * Imported drafts carry only the book's question; the reviewer supplies the
     * reference answer here before the exercise can be answered by students.
     */
    public function verifyExercise(Request $request, Exercise $exercise)
    {
        $data = $request->validate([
            'answer' => ['nullable', 'string'],
            'solution_steps' => ['nullable', 'array'],
            'hint' => ['nullable', 'string'],
        ]);

        $exercise->update(array_filter([
            'answer' => $data['answer'] ?? null,
            'solution_steps' => $data['solution_steps'] ?? null,
            'hint' => $data['hint'] ?? null,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'is_draft' => false,
        ], fn ($v) => ! is_null($v)));

        return response()->json(['exercise' => $exercise]);
    }

    /**
     * Ask the AI to suggest a reference answer, solution steps and hint for one
     * exercise. The suggestion is returned only — it is never written to the
     * published exercise until a human approves it (see verifyExercise).
     */
    public function suggestSolution(Request $request, Exercise $exercise)
    {
        return response()->json(['suggestion' => $this->ai->draftSolution($exercise, $request->user())]);
    }

    /**
     * Draft solutions for every unanswered exercise in a lesson, for reviewers
     * to work through in bulk. Suggestions are returned as a list; nothing is
     * published here.
     */
    public function suggestLessonSolutions(Request $request, Lesson $lesson)
    {
        $exercises = $lesson->exercises()
            ->where(fn ($q) => $q->whereNull('answer')->orWhere('answer', ''))
            ->with('options')
            ->get();

        $suggestions = $exercises->map(fn (Exercise $exercise) => [
            'exercise_id' => $exercise->id,
            'question' => $exercise->question,
            'suggestion' => $this->ai->draftSolution($exercise, $request->user()),
        ])->values();

        return response()->json([
            'lesson_id' => $lesson->id,
            'count' => $suggestions->count(),
            'suggestions' => $suggestions,
        ]);
    }

    public function generateSummary(Lesson $lesson)
    {
        return response()->json(['summary' => $this->ai->summarize($lesson)]);
    }

    public function approveSummary(Request $request, Summary $summary)
    {
        $summary->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        $summary->lesson?->update(['status' => 'published']);
        return response()->json(['summary' => $summary]);
    }

    /**
     * Teacher view: which lessons students struggle with most.
     */
    public function strugglingLessons()
    {
        $rows = \App\Models\Attempt::where('is_correct', false)
            ->with('exercise.lesson')
            ->get()
            ->groupBy(fn ($a) => $a->exercise?->lesson_id)
            ->map(function ($attempts) {
                $lesson = $attempts->first()->exercise?->lesson;
                return [
                    'lesson' => $lesson?->only(['id', 'title']),
                    'wrong_attempts' => $attempts->count(),
                    'students' => $attempts->pluck('student_profile_id')->unique()->count(),
                ];
            })
            ->filter(fn ($r) => $r['lesson'])
            ->sortByDesc('wrong_attempts')
            ->values()
            ->take(10);

        return response()->json(['lessons' => $rows]);
    }

    /**
     * Assign a teacher to a grade+subject (owner/reviewer action).
     */
    public function assignTeacher(Request $request)
    {
        $data = $request->validate([
            'teacher_user_id' => ['required', 'exists:users,id'],
            'grade_id' => ['nullable', 'exists:grades,id'],
            'subject_id' => ['nullable', 'exists:subjects,id'],
            'section' => ['nullable', 'string', 'max:30'],
        ]);
        $user = User::findOrFail($data['teacher_user_id']);
        $assignment = $user->teacherAssignments()->create($data);
        return response()->json(['assignment' => $assignment], 201);
    }
}
