<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\BookProcessingJob;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Summary;
use App\Models\Textbook;
use App\Models\TextbookPage;
use App\Models\User;
use App\Services\AiTeacherService;
use App\Services\BookPipelineService;
use App\Services\ContentExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TeacherController extends Controller
{
    public function __construct(
        private ContentExtractionService $extractor,
        private AiTeacherService $ai,
        private BookPipelineService $pipeline,
    ) {}

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

    /**
     * Edit a textbook's catalogue metadata (title/term/edition/publisher/branch/
     * authority). Needed so a book's branch can be corrected after import.
     */
    public function updateTextbook(Request $request, Textbook $textbook)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'term' => ['sometimes', 'required', 'in:first,second'],
            'edition' => ['nullable', 'string', 'max:60'],
            'publisher' => ['nullable', 'string', 'max:120'],
            'authority' => ['sometimes', 'required', 'in:common,sanaa,aden'],
            'branch' => ['sometimes', 'required', 'in:general,scientific,literary'],
        ]);

        $textbook->update($data);

        return response()->json(['textbook' => $textbook]);
    }

    public function uploadPdf(Request $request, Textbook $textbook)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:51200']]);

        $path = $request->file('file')->store('textbooks', config('platform.storage.textbook_disk', 'public'));
        $textbook->update([
            'file_path' => $path,
            'source_type' => 'pdf',
        ]);

        // A full book is far too slow for one request, so the upload starts a
        // resumable job and runs its first slice right away: small books finish
        // immediately, and large ones keep going as the client polls
        // /textbooks/{id}/job. Each poll advances exactly one bounded slice.
        $job = $this->pipeline->advance($this->pipeline->start($textbook));

        return response()->json([
            'textbook' => $textbook->fresh(),
            'job' => $job->fresh(),
            'pages_extracted' => $job->pages_done,
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
        $disk = Storage::disk(config('platform.storage.textbook_disk', 'public'));
        $pages = $textbook->pages()
            ->orderBy('page_number')
            ->get(['id', 'page_number', 'extracted_text', 'image_path', 'status', 'review_note'])
            ->map(function ($page) use ($disk) {
                $page->image_url = $page->image_path ? $disk->url($page->image_path) : null;

                return $page;
            });

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
            // Optional interactive model (see web/assets/interactive.js). Only the
            // descriptor is validated here; the client owns how each kind draws.
            'visual' => ['nullable', 'array'],
            'visual.kind' => ['required_with:visual', 'string', 'max:40'],
            'visual.params' => ['nullable', 'array'],
            'visual.prompt' => ['nullable', 'string', 'max:500'],
        ]);
        $section = $lesson->sections()->create($data + ['is_from_textbook' => false]);

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
        $rows = Attempt::where('is_correct', false)
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

    /**
     * The live processing status of a book, and one more step of work.
     *
     * The client polls this: each call advances the pipeline by one bounded slice
     * (a dozen pages of OCR, or a handful of lessons) and returns the refreshed
     * progress. That keeps every request short, makes the run resumable after a
     * restart, and means a slow book simply takes more polls.
     */
    public function jobStatus(Textbook $textbook)
    {
        $job = BookProcessingJob::firstOrCreate(
            ['textbook_id' => $textbook->id],
            ['status' => 'queued', 'stage' => 'في الانتظار', 'started_at' => now()],
        );

        if (in_array($job->status, BookProcessingJob::ACTIVE, true)) {
            $job = $this->pipeline->advance($job);
        }

        return response()->json([
            'job' => $job->fresh(),
            'percent' => $job->percent(),
            'pages' => $this->pipeline->pageStatus($textbook),
            'lessons' => $textbook->lessons()->count(),
            'models' => Lesson::where('textbook_id', $textbook->id)
                ->whereHas('sections', fn ($q) => $q->whereNotNull('visual'))
                ->count(),
        ]);
    }

    /** Start (or resume) processing without re-uploading the PDF. */
    public function startJob(Textbook $textbook)
    {
        $job = $this->pipeline->start($textbook);

        return response()->json(['job' => $job->fresh(), 'percent' => $job->percent()]);
    }

    /**
     * The generated models of a book, for quality review before publishing.
     * Every model carries the textbook page and sentence it was built from, so a
     * reviewer can check it against the source rather than trusting it blindly.
     */
    public function reviewModels(Textbook $textbook)
    {
        $lessons = $textbook->lessons()
            ->with(['sections' => fn ($q) => $q->whereNotNull('visual')])
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'page_from' => $lesson->page_from,
                'page_to' => $lesson->page_to,
                'status' => $lesson->status,
                'models' => $lesson->sections->map(fn ($s) => [
                    'section_id' => $s->id,
                    'kind' => $s->visual['kind'] ?? null,
                    // params let the reviewer render the exact model the student
                    // would see, rather than approving a description of it.
                    'params' => $s->visual['params'] ?? [],
                    'prompt' => $s->visual['prompt'] ?? null,
                    'source_page' => $s->visual['source_page'] ?? $s->source_page,
                    'content' => $s->content,
                ])->values(),
            ]);

        return response()->json([
            'textbook' => ['id' => $textbook->id, 'title' => $textbook->title, 'status' => $textbook->status],
            'lessons' => $lessons,
        ]);
    }

    /**
     * Approve a book's generated models and publish its lessons to students.
     * Publishing is deliberately a separate, explicit step: nothing a machine
     * generated reaches a learner until a human has approved it.
     */
    public function approveModels(Request $request, Textbook $textbook)
    {
        $data = $request->validate([
            'lesson_ids' => ['nullable', 'array'],
            'lesson_ids.*' => ['integer', 'exists:lessons,id'],
        ]);

        // Approving a subset publishes only those lessons; otherwise the whole
        // book is published.
        if (! empty($data['lesson_ids'])) {
            Lesson::where('textbook_id', $textbook->id)
                ->whereIn('id', $data['lesson_ids'])
                ->update(['status' => 'published']);
            $textbook->update(['status' => 'published']);

            return response()->json(['published' => count($data['lesson_ids'])]);
        }

        $job = $this->pipeline->approve($textbook, $request->user()?->name);

        return response()->json(['job' => $job, 'published' => $textbook->lessons()->count()]);
    }
}
