<?php

namespace App\Services;

use App\Models\BookProcessingJob;
use App\Models\Lesson;
use App\Models\Textbook;
use Illuminate\Support\Facades\Log;

/**
 * Drives one textbook from "PDF uploaded" to "interactive models awaiting
 * review", in resumable steps.
 *
 * Why steps and not one long call: a full Yemeni textbook is hundreds of pages
 * of Arabic OCR, and a single request would be killed by any deploy, timeout or
 * worker restart long before it finished. Every step here is bounded and records
 * its progress on the job row, so `advance()` can be called repeatedly and the
 * work simply continues from where it stopped. Because each stage is keyed to
 * rows that already exist (a page number, a lesson id), calling it twice never
 * duplicates anything.
 */
class BookPipelineService
{
    /** Pages OCR'd per step, small enough to finish inside one request. */
    public const PAGES_PER_STEP = 12;

    public function __construct(
        private ContentExtractionService $extractor,
        private InteractiveModelService $models,
    ) {}

    /**
     * Start (or resume) processing for a textbook. Safe to call repeatedly: an
     * already-running job is returned rather than restarted, so two uploads of
     * the same book cannot run the pipeline twice.
     */
    public function start(Textbook $textbook): BookProcessingJob
    {
        $job = BookProcessingJob::firstOrNew(['textbook_id' => $textbook->id]);

        // A job that already finished is not restarted by a stray call.
        if ($job->exists && $job->status === 'published') {
            return $job;
        }

        if (! $job->exists || $job->status === 'failed') {
            $job->fill([
                'status' => 'queued',
                'stage' => 'في الانتظار',
                'pages_total' => $textbook->pages_count ?: 0,
                'pages_done' => 0,
                'last_page_processed' => 0,
                'last_error' => null,
                'started_at' => now(),
                'finished_at' => null,
            ])->save();
        }

        return $job;
    }

    /**
     * Run the next unfinished stage and return the refreshed job. Returns the
     * job unchanged when it is already finished, so a polling loop is harmless.
     */
    public function advance(BookProcessingJob $job): BookProcessingJob
    {
        $textbook = $job->textbook;
        if ($textbook === null) {
            return $this->fail($job, 'الكتاب غير موجود.');
        }

        try {
            return match ($job->status) {
                'queued' => $this->extractStep($job, $textbook),
                'extracting' => $this->extractStep($job, $textbook),
                'segmenting' => $this->segmentStep($job, $textbook),
                'generating' => $this->generateStep($job, $textbook),
                default => $job, // review / published / failed: nothing to do
            };
        } catch (\Throwable $e) {
            Log::warning('Book pipeline failed', ['textbook' => $textbook->id, 'error' => $e->getMessage()]);

            return $this->fail($job, $e->getMessage());
        }
    }

    /**
     * Extract the next slice of pages. Resumes from `last_page_processed`, so a
     * killed run re-OCRs at most one slice, never the whole book.
     */
    private function extractStep(BookProcessingJob $job, Textbook $textbook): BookProcessingJob
    {
        if (! $textbook->file_path && ! $textbook->source_url) {
            // A book can arrive already carrying its pages: the teacher uploaded
            // page images one by one, or the pages were seeded/imported. There is
            // nothing left to extract, so move straight on to segmentation rather
            // than failing a book that is perfectly ready.
            $existing = $this->pageStatus($textbook);
            if ($existing['with_text'] > 0) {
                $job->update([
                    'status' => 'segmenting',
                    'stage' => 'تقسيم الكتاب إلى دروس',
                    'pages_total' => $existing['total'],
                    'pages_done' => $existing['total'],
                    'last_page_processed' => $existing['total'],
                ]);

                return $job;
            }

            return $this->fail($job, 'لا يوجد ملف PDF أو رابط مصدر ولا صفحات مُستخرجة.');
        }

        $job->update(['status' => 'extracting', 'stage' => 'استخراج النص و OCR']);

        $from = $job->last_page_processed + 1;

        // The extractor is page-addressable: hand it the slice and it upserts
        // those textbook_pages rows, leaving earlier pages untouched.
        $result = $this->extractor->extractPdfRange($textbook, $from, self::PAGES_PER_STEP);

        // A file we can neither read nor render has no pages at all. Without this
        // guard the job would sit in "extracting" forever, because the "slice
        // done" check below never becomes true.
        if ($result['pages_total'] === 0 && $result['processed'] === 0) {
            return $this->fail($job, 'تعذّر قراءة الملف: لا يحتوي صفحات قابلة للاستخراج.');
        }

        $job->pages_total = max($job->pages_total, $result['pages_total']);
        $job->pages_done = min($job->pages_total, $from + $result['processed'] - 1);
        $job->last_page_processed = min($job->pages_total, $from + $result['processed'] - 1);
        $job->stats = array_merge((array) $job->stats, ['ocr_pages' => $result['ocr_pages']]);

        if ($job->pages_done >= $job->pages_total && $job->pages_total > 0) {
            $job->status = 'segmenting';
            $job->stage = 'تقسيم الكتاب إلى دروس';
        }

        $job->save();

        return $job;
    }

    /** Split the extracted pages into lessons linked to their source pages. */
    private function segmentStep(BookProcessingJob $job, Textbook $textbook): BookProcessingJob
    {
        $job->update(['status' => 'segmenting', 'stage' => 'تقسيم الكتاب إلى دروس']);

        $lessons = $this->extractor->draftLessonsFromPages($textbook);

        $job->lessons_total = $lessons;
        $job->models_generated = 0;
        $job->status = 'generating';
        $job->stage = 'توليد النماذج التفاعلية';
        $job->save();

        return $job;
    }

    /** Generate models for the next slice of lessons that have not been tried yet. */
    private function generateStep(BookProcessingJob $job, Textbook $textbook): BookProcessingJob
    {
        $job->update(['stage' => 'توليد النماذج التفاعلية']);

        // Track *attempted* lessons, not merely lessons that ended up with a
        // model. Plenty of lessons legitimately contain nothing a model can show
        // (a rule with no figures, a reading passage), and if those were retried
        // the pipeline would never finish.
        $stats = (array) $job->stats;
        $processed = $stats['processed_lessons'] ?? [];

        $pending = Lesson::where('textbook_id', $textbook->id)
            ->when($processed, fn ($q) => $q->whereNotIn('id', $processed))
            ->orderBy('sort_order')
            ->limit(10)
            ->get();

        foreach ($pending as $lesson) {
            $this->models->generateForLesson($lesson);
            $processed[] = $lesson->id;
        }

        $stats['processed_lessons'] = array_values(array_unique($processed));
        $job->stats = $stats;
        $job->models_generated = Lesson::where('textbook_id', $textbook->id)
            ->whereHas('sections', fn ($q) => $q->whereNotNull('visual'))
            ->count();

        if ($pending->isEmpty()) {
            $job->status = 'review';
            $job->stage = 'بانتظار المراجعة والاعتماد';
            $job->finished_at = now();
            $textbook->update(['status' => 'review']);
        }

        $job->save();

        return $job;
    }

    /**
     * Approve and publish every generated model for a book: flips its lessons
     * from draft to published so students can see them.
     */
    public function approve(Textbook $textbook, ?string $reviewer = null): BookProcessingJob
    {
        $job = BookProcessingJob::firstOrNew(['textbook_id' => $textbook->id]);

        $published = Lesson::where('textbook_id', $textbook->id)->update(['status' => 'published']);
        $textbook->update(['status' => 'published']);

        $job->fill([
            'status' => 'published',
            'stage' => 'منشور للطلاب',
            'models_approved' => $job->models_generated,
            'finished_at' => now(),
        ])->save();

        return $job;
    }

    /** A book that has pages but no readable text should say so, not hang. */
    public function pageStatus(Textbook $textbook): array
    {
        return [
            'total' => $textbook->pages()->count(),
            'with_text' => $textbook->pages()->where('extracted_text', '!=', '')->count(),
        ];
    }

    private function fail(BookProcessingJob $job, string $message): BookProcessingJob
    {
        $job->update(['status' => 'failed', 'stage' => 'فشل', 'last_error' => $message, 'finished_at' => now()]);

        return $job;
    }
}
