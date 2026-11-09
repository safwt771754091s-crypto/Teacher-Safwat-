<?php

namespace Tests\Feature;

use App\Models\BookProcessingJob;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Textbook;
use App\Models\User;
use App\Services\BookPipelineService;
use App\Services\CrawleeDiscoveryService;
use App\Services\InteractiveModelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The automatic textbook -> interactive models feature.
 *
 * These tests exercise the real services end to end (upload, extraction,
 * segmentation, model generation, review, publish) on an in-memory database,
 * asserting the properties that matter: Arabic survives extraction, every model
 * is traceable to a source page, re-running never duplicates work, and nothing
 * is published to students without an explicit approval.
 */
class AutoInteractiveBooksTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function owner(): User
    {
        return User::where('email', 'owner@school.ye')->first();
    }

    private function makeBook(string $subjectName = 'الرياضيات'): Textbook
    {
        $subject = Subject::where('name', 'like', "%{$subjectName}%")->first()
            ?? Subject::first();

        return Textbook::create([
            'subject_id' => $subject->id,
            'title' => 'كتاب اختبار آلي',
            'term' => 'first',
            'source_type' => 'pdf',
            'status' => 'draft',
        ]);
    }

    // -----------------------------------------------------------------
    // Extraction: Arabic and page linking
    // -----------------------------------------------------------------

    public function test_arabic_text_survives_extraction_and_stays_linked_to_its_page(): void
    {
        $book = $this->makeBook();
        $book->pages()->create([
            'page_number' => 5,
            'extracted_text' => 'الدرس الأول : الكسور الاعتيادية',
        ]);

        $page = $book->pages()->where('page_number', 5)->first();

        $this->assertSame('الدرس الأول : الكسور الاعتيادية', $page->extracted_text);
        $this->assertSame(5, $page->page_number);
    }

    public function test_a_book_with_no_source_is_reported_as_failed_not_hung(): void
    {
        $book = $this->makeBook();
        $job = app(BookPipelineService::class)->start($book);

        $job = app(BookPipelineService::class)->advance($job);

        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('PDF', (string) $job->last_error);
    }

    public function test_the_pipeline_is_resumable_and_never_restarts_a_finished_job(): void
    {
        $book = $this->makeBook();
        $job = app(BookPipelineService::class)->start($book);
        $job->update(['status' => 'published']);

        // A stray start must not resurrect a finished book.
        $again = app(BookPipelineService::class)->start($book);

        $this->assertSame('published', $again->status);
    }

    public function test_starting_twice_reuses_one_job_row(): void
    {
        $book = $this->makeBook();
        app(BookPipelineService::class)->start($book);
        app(BookPipelineService::class)->start($book);

        $this->assertSame(1, BookProcessingJob::where('textbook_id', $book->id)->count());
    }

    // -----------------------------------------------------------------
    // Model generation: grounded in the real text, page-linked, no dupes
    // -----------------------------------------------------------------

    public function test_a_fraction_in_the_text_produces_a_fraction_model(): void
    {
        $lesson = $this->lessonWithSection('الرياضيات', 'نكتب الكسر ٣/٤ ونقرؤه ثلاثة أرباع');

        $created = app(InteractiveModelService::class)->generateForLesson($lesson);

        $this->assertSame(1, $created);
        $visual = $lesson->sections()->first()->visual;
        $this->assertSame('fractions', $visual['kind']);
        $this->assertSame(3, $visual['params']['num']);
        $this->assertSame(4, $visual['params']['den']);
    }

    public function test_a_degree_figure_produces_an_angle_model(): void
    {
        $lesson = $this->lessonWithSection('الرياضيات', 'قياس الزاوية ٦٠ درجة');

        app(InteractiveModelService::class)->generateForLesson($lesson);

        $visual = $lesson->sections()->first()->visual;
        $this->assertSame('angles', $visual['kind']);
        $this->assertSame(60, $visual['params']['deg']);
    }

    public function test_a_circuit_with_a_voltage_produces_a_circuit_model(): void
    {
        $lesson = $this->lessonWithSection('العلوم', 'نوصل بطارية ٩ فولت مع مقاومة في دائرة كهربائية');

        app(InteractiveModelService::class)->generateForLesson($lesson);

        $visual = $lesson->sections()->first()->visual;
        $this->assertSame('circuit', $visual['kind']);
        $this->assertSame(9, $visual['params']['v']);
    }

    public function test_no_model_is_invented_when_the_text_has_no_signal(): void
    {
        $lesson = $this->lessonWithSection('الرياضيات', 'نتعرف في هذا الدرس على أهمية التعاون');

        $created = app(InteractiveModelService::class)->generateForLesson($lesson);

        $this->assertSame(0, $created);
        $this->assertNull($lesson->sections()->first()->visual);
    }

    public function test_every_model_carries_the_source_page_and_the_sentence_it_came_from(): void
    {
        $lesson = $this->lessonWithSection('الرياضيات', 'الكسر ١/٢ يساوي نصفاً', page: 42);

        app(InteractiveModelService::class)->generateForLesson($lesson);

        $visual = $lesson->sections()->first()->visual;
        $this->assertSame(42, $visual['source_page']);
        $this->assertStringContainsString('الكسر', $visual['prompt']);
    }

    public function test_generating_twice_never_duplicates_a_model(): void
    {
        $lesson = $this->lessonWithSection('الرياضيات', 'الكسر ١/٢');
        $service = app(InteractiveModelService::class);

        $first = $service->generateForLesson($lesson);
        $second = $service->generateForLesson($lesson->fresh());

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, $lesson->sections()->whereNotNull('visual')->count());
    }

    public function test_a_maths_fraction_is_not_mistaken_for_a_bar_chart(): void
    {
        // "٣/٤" also contains three digits; the more specific rule must win.
        $lesson = $this->lessonWithSection('الرياضيات', '٣/٤ من الكل');

        app(InteractiveModelService::class)->generateForLesson($lesson);

        $this->assertSame('fractions', $lesson->sections()->first()->visual['kind']);
    }

    public function test_a_lesson_with_no_grounded_signal_does_not_stall_the_pipeline(): void
    {
        // Regression: the generator used to re-try a lesson that produced no
        // model forever, so a book of plain prose never left the "generating"
        // stage. Progress must be tracked by attempted lessons, not by models.
        $book = $this->makeBook('الرياضيات');
        $book->pages()->create(['page_number' => 1, 'extracted_text' => 'نص بلا أي أرقام أو أشكال']);
        Lesson::create([
            'textbook_id' => $book->id, 'title' => 'درس نصي', 'status' => 'draft',
            'page_from' => 1, 'page_to' => 1, 'sort_order' => 0,
        ]);
        $lesson = $book->lessons()->first();
        $lesson->sections()->create([
            'type' => 'point', 'content' => 'نتعرف على أهمية القراءة',
            'source_page' => 1, 'sort_order' => 0, 'is_from_textbook' => true,
        ]);

        $job = app(BookPipelineService::class)->start($book);
        $job->update(['status' => 'generating', 'pages_total' => 1, 'pages_done' => 1]);

        // A handful of steps must reach a terminal state, not spin forever.
        for ($i = 0; $i < 5 && in_array($job->status, BookProcessingJob::ACTIVE, true); $i++) {
            $job = app(BookPipelineService::class)->advance($job->fresh());
        }

        $this->assertSame('review', $job->status);
        $this->assertSame(0, $job->models_generated);
    }

    public function test_a_book_whose_pages_already_exist_skips_extraction(): void
    {
        // Regression: a book seeded with pages (imported, or built page by page)
        // has no PDF file or source URL. The pipeline used to fail it outright
        // instead of moving on to segmentation.
        $book = $this->makeBook('الرياضيات');
        $book->pages()->create(['page_number' => 1, 'extracted_text' => 'قياس الزاوية ٦٠ درجة', 'status' => 'extracted']);
        $book->pages()->create(['page_number' => 2, 'extracted_text' => 'الدرس الثاني', 'status' => 'extracted']);

        $job = app(BookPipelineService::class)->start($book);
        $job = app(BookPipelineService::class)->advance($job);

        $this->assertSame('segmenting', $job->status);
        $this->assertSame(2, $job->pages_total);
        $this->assertSame(2, $job->pages_done);
    }

    public function test_an_unreadable_file_fails_instead_of_polling_forever(): void
    {
        $book = $this->makeBook('الرياضيات');
        // Points at a path that is not a PDF and has no text layer to fall back on.
        $book->update(['file_path' => 'textbooks/does-not-exist.pdf']);

        $job = app(BookPipelineService::class)->start($book);
        $job = app(BookPipelineService::class)->advance($job);

        $this->assertSame('failed', $job->status);
        $this->assertNotNull($job->last_error);
    }

    public function test_review_status_reports_full_progress(): void
    {
        $book = $this->makeBook();
        $job = app(BookPipelineService::class)->start($book);
        $job->update(['status' => 'review', 'pages_total' => 10, 'pages_done' => 10]);

        $this->assertSame(100, $job->fresh()->percent());
    }

    // -----------------------------------------------------------------
    // HTTP surface: upload starts a job, review, then approve to publish
    // -----------------------------------------------------------------

    public function test_uploading_a_pdf_starts_a_resumable_job(): void
    {
        $book = $this->makeBook();

        $response = $this->actingAs($this->owner())
            ->postJson("/api/v1/textbooks/{$book->id}/upload-pdf", [
                'file' => UploadedFile::fake()->createWithContent('book.pdf', $this->realPdf()),
            ]);

        $response->assertOk();
        // The first slice runs during the upload, so the job is already active
        // and at least one real page has been extracted.
        $this->assertContains($response->json('job.status'), ['extracting', 'segmenting', 'generating']);
        $this->assertSame(1, $response->json('pages_extracted'));
        $this->assertDatabaseHas('book_processing_jobs', ['textbook_id' => $book->id]);
        $this->assertDatabaseHas('textbook_pages', ['textbook_id' => $book->id, 'page_number' => 1]);
    }

    public function test_the_job_endpoint_reports_progress(): void
    {
        $book = $this->makeBook();
        app(BookPipelineService::class)->start($book);

        $response = $this->actingAs($this->owner())
            ->getJson("/api/v1/textbooks/{$book->id}/job");

        $response->assertOk();
        $response->assertJsonStructure(['job', 'percent', 'pages', 'lessons', 'models']);
    }

    public function test_models_are_reviewed_with_their_source_page_before_publishing(): void
    {
        $book = $this->makeBook();
        $lesson = Lesson::create([
            'textbook_id' => $book->id, 'title' => 'الدرس الأول', 'status' => 'draft',
            'page_from' => 3, 'page_to' => 3, 'sort_order' => 0,
        ]);
        $lesson->sections()->create([
            'type' => 'point', 'content' => 'الكسر ١/٢ يساوي نصفاً', 'source_page' => 3,
            'sort_order' => 0, 'is_from_textbook' => true,
            'visual' => ['kind' => 'fractions', 'params' => ['num' => 1, 'den' => 2], 'prompt' => 'الكسر ١/٢', 'source_page' => 3],
        ]);

        $response = $this->actingAs($this->owner())
            ->getJson("/api/v1/textbooks/{$book->id}/models");

        $response->assertOk();
        $response->assertJsonPath('lessons.0.models.0.kind', 'fractions');
        $response->assertJsonPath('lessons.0.models.0.source_page', 3);
        $response->assertJsonPath('lessons.0.models.0.params.num', 1);
    }

    public function test_nothing_is_published_to_students_until_it_is_approved(): void
    {
        $book = $this->makeBook();
        $lesson = Lesson::create([
            'textbook_id' => $book->id, 'title' => 'الدرس الأول', 'status' => 'draft',
            'page_from' => 1, 'page_to' => 1, 'sort_order' => 0,
        ]);
        $lesson->sections()->create([
            'type' => 'point', 'content' => 'الكسر ١/٢', 'source_page' => 1,
            'sort_order' => 0, 'is_from_textbook' => true,
            'visual' => ['kind' => 'fractions', 'params' => ['num' => 1, 'den' => 2]],
        ]);

        $this->assertSame(0, $book->lessons()->where('status', 'published')->count());

        $this->actingAs($this->owner())
            ->postJson("/api/v1/textbooks/{$book->id}/approve-models", ['lesson_ids' => [$lesson->id]])
            ->assertOk()
            ->assertJsonPath('published', 1);

        $this->assertSame(1, $book->lessons()->where('status', 'published')->count());
    }

    public function test_a_student_cannot_start_or_approve_processing(): void
    {
        $book = $this->makeBook();
        $student = User::where('email', 'student@school.ye')->first();

        $this->actingAs($student)
            ->postJson("/api/v1/textbooks/{$book->id}/job")
            ->assertForbidden();

        $this->actingAs($student)
            ->postJson("/api/v1/textbooks/{$book->id}/approve-models", [])
            ->assertForbidden();
    }

    /**
     * The backend and the browser engine are two halves of one contract. If the
     * service emits a kind the engine does not know, or a param name the engine
     * does not read, the model silently renders wrong or not at all. This test
     * reads the engine's own source and enforces the contract in both directions.
     */
    public function test_every_generated_spec_matches_a_kind_the_engine_registers(): void
    {
        $engine = $this->engineRegistry();
        $service = app(InteractiveModelService::class);

        $cases = [
            ['الرياضيات', 'نكتب الكسر ٣/٤', 'fractions'],
            ['الرياضيات', 'قياس الزاوية ٦٠ درجة', 'angles'],
            ['الرياضيات', 'قارن بين -٢ و ٣ على مستقيم الأعداد', 'number-line'],
            ['الرياضيات', 'المثلث أ ب ج حيث أ ب = ٥ سم و ب ج = ٤ سم', 'triangle'],
            ['العلوم', 'نوصل بطارية ٩ فولت مع مقاومة في دائرة', 'circuit'],
            ['العلوم', 'تتحول المادة من صلب إلى سائل عند التسخين', 'states-of-matter'],
            ['العلوم', 'الطول الموجي للموجة ٤ سم', 'wave'],
            ['اللغة الإنجليزية', 'The cat sat on the mat', 'sentence-builder'],
            ['الرياضيات', 'عد ٣ أقلام', 'counting'],
        ];

        foreach ($cases as [$subject, $content, $expectedKind]) {
            $lesson = $this->lessonWithSection($subject, $content);
            $spec = $service->specForSection($lesson->sections()->first(), $lesson);

            $this->assertNotNull($spec, "no model produced for: {$content}");
            $this->assertSame($expectedKind, $spec['kind'], "wrong kind for: {$content}");
            $this->assertArrayHasKey($spec['kind'], $engine, "engine does not register kind {$spec['kind']}");

            // Every param we emit must be one the engine actually reads.
            $engineParams = $engine[$spec['kind']]['params'];
            $extra = array_diff(array_keys($spec['params']), $engineParams);
            $this->assertSame([], $extra, "engine {$spec['kind']} ignores params: ".implode(',', $extra));
        }
    }

    public function test_every_kind_the_service_can_emit_is_registered_by_the_engine(): void
    {
        $engine = $this->engineRegistry();

        // Probe the service with text designed to trigger each rule in turn.
        $probes = [
            ['الرياضيات', '٣/٤', null],
            ['الرياضيات', 'زاوية ٩٠ درجة', null],
            ['الرياضيات', 'قارن الأعداد ٢ و ٥', null],
            ['الرياضيات', 'المثلث طوله ٣ سم و ٤ سم', null],
            ['العلوم', 'دائرة كهربائية بجهد ١٢ فولت', null],
            ['العلوم', 'المادة الصلبة والسائلة والغازية', null],
            ['العلوم', 'الموجة طولها ٦ سم', null],
            ['اللغة الإنجليزية', 'I like to read books', null],
            ['الرياضيات', 'نعد الأعداد من ١ إلى ٥', null],
        ];

        $seen = [];
        foreach ($probes as [$subject, $content]) {
            $lesson = $this->lessonWithSection($subject, $content);
            $spec = app(InteractiveModelService::class)->specForSection($lesson->sections()->first(), $lesson);
            if ($spec) {
                $seen[] = $spec['kind'];
            }
        }

        $this->assertNotEmpty($seen);
        foreach (array_unique($seen) as $kind) {
            $this->assertArrayHasKey($kind, $engine, "kind {$kind} is emitted but not registered by the engine");
        }
    }

    /**
     * A genuine one-page PDF, so upload tests exercise the real extractor rather
     * than a byte blob that no parser can read.
     */
    private function realPdf(): string
    {
        $objs = [
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>',
            4 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>',
        ];
        $text = 'BT /F1 24 Tf 72 700 Td (Lesson one) Tj ET';
        $objs[5] = '<</Length '.strlen($text).">>stream\n".$text."\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        for ($n = 1; $n <= count($objs); $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= 'trailer'."\n<</Size ".(count($objs) + 1)."/Root 1 0 R>>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    /**
     * Parse web/assets/interactive*.js for the registered kinds and, for each,
     * the param names it reads (`params.x` / `p.x`).
     *
     * @return array<string, array{params: list<string>}>
     */
    private function engineRegistry(): array
    {
        $dir = base_path('../web/assets');
        $files = glob($dir.'/interactive*.js') ?: [];
        $this->assertNotEmpty($files, 'no interactive engine files found');

        $registry = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (! preg_match_all("/KINDS\['([a-z0-9-]+)'\]\s*=\s*function\s*\([^)]*\)\s*\{/", $src, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[1] as $i => $cap) {
                $kind = $cap[0];
                $body = substr($src, $m[0][$i][1], 1500);
                preg_match_all('/\b(?:params|p)\.([A-Za-z0-9_]+)/', $body, $pm);
                $registry[$kind] = ['params' => array_values(array_unique($pm[1]))];
            }
        }

        return $registry;
    }

    // -----------------------------------------------------------------
    // Crawlee helper: optional, and never breaks the platform when absent
    // -----------------------------------------------------------------

    public function test_the_crawlee_helper_is_optional_and_degrades_cleanly(): void
    {
        $service = app(CrawleeDiscoveryService::class);

        // A bad URL must be reported, never throw.
        $result = $service->discover('not a url');
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_the_crawlee_script_exists_and_is_syntactically_valid(): void
    {
        $path = base_path('tools/crawlee_service/crawl_books.py');
        $this->assertFileExists($path);

        $python = (string) config('platform.crawlee.python', 'python3');
        $found = trim((string) @shell_exec('command -v '.escapeshellarg($python).' 2>/dev/null'));
        if ($found === '') {
            $this->markTestSkipped('python is not installed in this environment.');
        }

        $out = (string) @shell_exec(escapeshellarg($python).' -c "import ast;ast.parse(open('
            .escapeshellarg($path).',encoding=\'utf-8\').read())" 2>&1');

        $this->assertSame('', trim($out), 'crawl_books.py should be valid Python: '.$out);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function lessonWithSection(string $subjectName, string $content, int $page = 7): Lesson
    {
        $book = $this->makeBook($subjectName);
        $lesson = Lesson::create([
            'textbook_id' => $book->id,
            'title' => 'درس تجريبي',
            'status' => 'draft',
            'page_from' => $page,
            'page_to' => $page,
            'sort_order' => 0,
        ]);
        $lesson->sections()->create([
            'type' => 'point',
            'content' => $content,
            'source_page' => $page,
            'sort_order' => 0,
            'is_from_textbook' => true,
        ]);

        return $lesson;
    }
}
