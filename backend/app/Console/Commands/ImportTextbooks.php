<?php

namespace App\Console\Commands;

use App\Models\Grade;
use App\Models\Subject;
use App\Models\Textbook;
use App\Services\ContentExtractionService;
use Illuminate\Console\Command;

/**
 * Bulk-import official Yemeni textbooks from a JSON manifest of {title, url,
 * grade_level, subject, term, authority, branch}. Each book is attached to (or
 * creates) the matching subject, downloaded, then extracted page-by-page with
 * the Arabic OCR pipeline. Nothing is invented: only the book's own pages are
 * stored, marked for human review.
 */
class ImportTextbooks extends Command
{
    protected $signature = 'textbooks:import
        {manifest : Path to the JSON manifest}
        {--limit=0 : Import only the first N entries}
        {--offset=0 : Skip the first N manifest entries (for sharding)}
        {--count=0 : Process at most N entries after the offset (for sharding)}
        {--grade= : Only this grade level}
        {--authority= : Only entries with this authority (common|sanaa|aden)}
        {--tag-authority= : Force this authority on every imported book}
        {--no-ocr : Disable OCR (text layer only)}
        {--draft-lessons : Draft lessons after extraction}
        {--keep-files : Keep the downloaded PDFs}
        {--max-seconds=0 : Stop gracefully after this many seconds (0 = no limit)}
        {--json : Emit a single JSON summary instead of human output}
        {--force : Re-extract even when the book already has readable text}';

    protected $description = 'Download and ingest a manifest of official Yemeni textbook PDFs';

    /** @var list<string> */
    private array $failures = [];

    /** Wall-clock start, used to honour --max-seconds. */
    private float $startedAt = 0.0;

    /** Set when the run stopped mid-book, so the resume offset is not advanced. */
    private bool $consumedBack = false;

    /**
     * Manifest subject name => the canonical subject name seeded for the grade.
     * Used only to avoid duplicate subjects; an unknown name is created as-is.
     *
     * @var array<string,string>
     */
    private const SUBJECT_ALIASES = [
        'اللغة العربية' => 'لغتي الجميلة (العربية)',
        'اللغة العربية (لغتي الجميلة)' => 'لغتي الجميلة (العربية)',
        'لغتي الجميلة' => 'لغتي الجميلة (العربية)',
        'الحاسوب' => 'مهارات الحاسوب',
        'الفلسفة وعلم النفس' => 'الفلسفة',
        'التربية الوطنية' => 'المواطنة',
    ];

    public function handle(ContentExtractionService $extractor): int
    {
        $manifest = json_decode((string) file_get_contents((string) $this->argument('manifest')), true);
        if (! is_array($manifest)) {
            $this->error('Invalid manifest JSON.');

            return self::FAILURE;
        }

        $this->startedAt = microtime(true);
        $json = (bool) $this->option('json');
        $maxSeconds = (int) $this->option('max-seconds');
        $say = fn (string $msg) => $json ? null : $this->line($msg);

        // Soft deadline for the slow steps (download, OCR). It leaves a margin so
        // the batch can persist what it read and return *before* the caller's own
        // timeout; the whole point is that a web-triggered batch never runs long.
        $deadline = $maxSeconds > 0
            ? $this->startedAt + $maxSeconds - min(10.0, max(2.0, $maxSeconds * 0.15))
            : null;

        if ($this->option('no-ocr')) {
            config(['platform.ocr.enabled' => false]);
        }

        $limit = (int) $this->option('limit');
        $onlyGrade = $this->option('grade') !== null ? (int) $this->option('grade') : null;
        $dir = storage_path('app/imported');
        @mkdir($dir, 0775, true);

        $done = 0;
        $skipped = 0;
        $timedOut = false;
        $onlyAuthority = $this->option('authority');
        if ($tagAuthority = $this->option('tag-authority')) {
            foreach ($manifest as &$entry) {
                $entry['authority'] = $tagAuthority;
            }
            unset($entry);
        }

        $offset = max(0, (int) $this->option('offset'));
        $count = max(0, (int) $this->option('count'));
        $seen = 0;      // entries passing filters (for --offset)
        $consumed = 0;  // entries this run examined (for the resume offset)
        $taken = 0;

        foreach ($manifest as $entry) {
            if ($onlyGrade && (int) ($entry['grade_level'] ?? 0) !== $onlyGrade) {
                continue;
            }
            if ($onlyAuthority && ($entry['authority'] ?? 'common') !== $onlyAuthority) {
                continue;
            }
            // Shard across records that pass the filters, so parallel workers
            // each take a disjoint, deterministic slice of the same manifest.
            if ($seen++ < $offset) {
                continue;
            }
            if ($count && $taken >= $count) {
                break;
            }
            // Stop before starting a new (slow) book once the budget is spent,
            // so a web-triggered run always returns in time and can resume.
            if ($maxSeconds > 0 && (microtime(true) - $this->startedAt) >= $maxSeconds) {
                $timedOut = true;
                break;
            }
            $taken++;
            $consumed++;
            if ($limit && $done >= $limit) {
                break;
            }

            $textbook = $this->upsertTextbook($entry);
            if (! $textbook) {
                $say('Skip (no matching subject): '.($entry['title'] ?? '?'));

                continue;
            }

            // Resume: skip only books that are BOTH fully stored and readable.
            // A scanned book (or one left with font-mojibake) must be retried so
            // OCR fills it in, and a partially-extracted book (a batch that ran
            // out of time) must continue from where it stopped.
            // Count in SQL instead of loading every page's text: a book can hold
            // thousands of pages of OCR text, and hydrating it just to decide
            // whether the book is finished costs more than the extraction.
            $stored = $textbook->pages()->count();
            // Seeded outline stubs must not count as readable content: a book
            // holding only stubs is not ingested, it is still an outline.
            $readable = $textbook->pages()
                ->where('extracted_text', '!=', '')
                ->where('extracted_text', 'not like', '%محتوى هيكلي من فهرس المنهج%')
                ->where('extracted_text', 'not like', '%نتعرف في هذا الدرس على%')
                ->count();
            $total = max(1, (int) $textbook->pages_count);
            // Skip a book when it was fully ingested on an earlier run, or when
            // it already holds real text on nearly every page (adopted from a
            // pre-marker import). A book with only outline stubs fails both, so
            // it is fetched and OCR'd; a book left half-done resumes.
            $richAlready = $stored >= $total && $readable >= (int) ceil($total * 0.9);
            if (! $this->option('force') && ($textbook->ingested_at !== null || $richAlready)) {
                $say('↷ already ingested: '.$textbook->title);
                $done++;
                $skipped++;

                continue;
            }

            $say('▶ '.$entry['title']);
            $file = $this->download($entry['url'], $dir, 2, $deadline);
            if (! $file) {
                // Ran out of time mid-download: stop here and retry this book on
                // the next call instead of skipping past it.
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $this->consumedBack = true;
                    $timedOut = true;
                    break;
                }
                if (! $json) {
                    $this->error('  download failed');
                }
                $this->failures[] = $entry['url'];

                continue;
            }

            // Extract in page slices, checking the wall-clock budget between
            // slices: a long scanned book then returns in time and the next call
            // resumes exactly where this one stopped (pages are upserted).
            $page = $this->resumePage($textbook, $extractor);
            $withText = 0;
            $slice = 12;
            $partial = false;
            $total = max(1, (int) $textbook->pages_count);
            try {
                while (true) {
                    if ($deadline !== null && microtime(true) >= $deadline) {
                        $partial = true;
                        break;
                    }
                    $pages = $extractor->extractPdfRange($textbook, $page, $slice, $file, $deadline);
                    $total = (int) $pages['pages_total'];
                    if ($total === 0 || $pages['processed'] === 0) {
                        break;
                    }
                    $page += $slice;
                    if ($page > $total) {
                        break;
                    }
                    if ($maxSeconds > 0 && (microtime(true) - $this->startedAt) >= $maxSeconds) {
                        $partial = true;
                        break;
                    }
                }
                $withText = $textbook->pages()->whereRaw('LENGTH(extracted_text) > 0')->count();
            } catch (\Throwable $e) {
                if (! $json) {
                    $this->error('  extraction failed: '.$e->getMessage());
                }
                $this->failures[] = $entry['url'];
                if (! $this->option('keep-files')) {
                    @unlink($file);
                }

                continue;
            }

            if ($partial) {
                // Stop here: do not advance past this book, so the next call
                // retries it and continues from the last stored page (pages are
                // upserted, so nothing is redone).
                if (! $this->option('keep-files')) {
                    @unlink($file);
                }
                $this->consumedBack = true;
                $timedOut = true;
                $say("  {$textbook->title}: partial ({$page}/{$total} pages so far) — will resume");
                break;
            }

            $say("  {$textbook->title}: {$total} pages ({$withText} with text)");

            if ($this->option('draft-lessons')) {
                $created = $extractor->draftLessonsFromPages($textbook);
                $say("  drafted {$created} lessons");
            }

            // Mark the book ingested only now that its pages are fully stored, so
            // a later run skips it instead of re-downloading and re-OCRing it.
            $textbook->forceFill(['ingested_at' => now()])->save();

            if (! $this->option('keep-files')) {
                @unlink($file);
            }
            $done++;
        }

        if ($this->consumedBack) {
            $consumed = max(0, $consumed - 1);
        }
        $nextOffset = $offset + $consumed;

        if ($json) {
            $this->line(json_encode([
                'ok' => true,
                'imported' => $done,
                'skipped' => $skipped,
                'failed' => count($this->failures),
                'failures' => $this->failures,
                'offset' => $offset,
                'next_offset' => $nextOffset,
                'consumed' => $consumed,
                'timed_out' => $timedOut,
            ], JSON_UNESCAPED_UNICODE));
        } else {
            $this->info("Imported {$done} textbook(s).");
            if ($this->failures) {
                $this->warn('Failed ('.count($this->failures).'):');
                foreach ($this->failures as $url) {
                    $this->warn('  '.$url);
                }
            }
        }

        return self::SUCCESS;
    }

    private function upsertTextbook(array $entry): ?Textbook
    {
        $grade = Grade::where('level', (int) $entry['grade_level'])->first();
        if (! $grade) {
            return null;
        }

        $subject = Subject::firstOrCreate(
            ['grade_id' => $grade->id, 'name' => $this->resolveSubjectName($grade->id, (string) $entry['subject'])],
            ['icon' => 'book', 'color' => '#2f6a43', 'sort_order' => 99, 'is_active' => true],
        );

        $term = $entry['term'] ?? 'first';

        // Attach to the book the curriculum already seeded for this subject+term
        // instead of forking a second book under the manifest's marketing title.
        // The subject+term pair is the identity of the textbook; its title is a
        // label. Matching on the title created a duplicate book whose real
        // lessons sat beside — but apart from — the seeded outline, so the
        // student still saw the outline.
        $textbook = Textbook::where('subject_id', $subject->id)
            ->where('term', $term)
            ->orderBy('id')
            ->first()
            ?? new Textbook(['subject_id' => $subject->id, 'term' => $term]);

        $textbook->fill([
            'term' => $term,
            'edition' => 'الطبعة الرسمية',
            'publisher' => 'وزارة التربية والتعليم - الجمهورية اليمنية',
            'authority' => $entry['authority'] ?? 'common',
            'branch' => $entry['branch'] ?? 'general',
            'source_type' => 'pdf',
            // Keep the official source so pages can be re-rendered on demand.
            'source_url' => $entry['url'] ?? null,
            'status' => 'review',
        ]);
        // Keep the curriculum label for a book that already had one; a brand-new
        // book takes the manifest's title.
        if (! $textbook->exists || $textbook->title === null) {
            $textbook->title = $entry['title'];
        }
        $textbook->save();

        return $textbook;
    }

    /**
     * Map a manifest subject name onto the name already seeded for that grade so
     * an import never forks a second subject row for the same school subject
     * (e.g. the manifest says «اللغة العربية» while grades 1–3 seed
     * «لغتي الجميلة (العربية)»).
     */
    private function resolveSubjectName(int $gradeId, string $manifestName): string
    {
        $existing = Subject::where('grade_id', $gradeId)->pluck('name')->all();
        $normalized = $this->normalizeSubject($manifestName);

        foreach ($existing as $name) {
            if ($this->normalizeSubject($name) === $normalized) {
                return $name;
            }
        }

        foreach (self::SUBJECT_ALIASES as $alias => $canonical) {
            if ($this->normalizeSubject($alias) !== $normalized) {
                continue;
            }
            foreach ($existing as $name) {
                if ($this->normalizeSubject($name) === $this->normalizeSubject($canonical)) {
                    return $name;
                }
            }
        }

        return $manifestName;
    }

    private function normalizeSubject(string $name): string
    {
        $name = preg_replace('/\([^)]*\)/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    /** The next page to extract, so a batch that ran out of time resumes here. */
    private function resumePage(Textbook $textbook, ContentExtractionService $extractor): int
    {
        // Return the first page that still lacks real book text — a missing row,
        // an empty page, or a seeded outline stub. A book whose front pages hold
        // stubs while its tail is already extracted must resume at the front, not
        // past the tail, or the real front pages are never fetched.
        $total = max(1, (int) $textbook->pages_count);
        $pages = $textbook->pages()
            ->where('page_number', '<=', $total)
            ->orderBy('page_number')
            ->get(['page_number', 'extracted_text'])
            ->keyBy('page_number');

        for ($n = 1; $n <= $total; $n++) {
            $text = (string) ($pages[$n]->extracted_text ?? '');
            if (! $extractor->textIsReadable($text) || $extractor->isPlaceholderPageText($text)) {
                return $n;
            }
        }

        return $total + 1;
    }

    private function download(string $url, string $dir, int $attempts = 1, ?float $deadline = null): ?string
    {
        $file = $dir.'/'.substr(sha1($url), 0, 12).'.pdf';
        // The official host serves the PDF only when the request looks like it
        // came from its own download page; without the referer it bounces us to
        // the homepage, so a naive download "succeeds" with an HTML file and the
        // book is silently never ingested.
        $url = $this->normalizeDownloadUrl($url);
        $referer = 'https://www.yemenbooks.com/';
        // Never outlive the batch budget: a slow host must not hold a web request
        // open past its limit, or the caller times out and the book never lands.
        $maxTime = $deadline !== null
            ? max(15, min(900, (int) ($deadline - microtime(true))))
            : 900;
        // curl's own retries multiply the wall time; when a deadline is set we do
        // a single bounded attempt so the batch cannot overshoot.
        $retries = $deadline !== null ? 0 : 2;
        for ($i = 0; $i < $attempts; $i++) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }
            @shell_exec(sprintf(
                'curl -sL --fail --max-time %d --retry %d -e %s -o %s %s',
                $maxTime,
                $retries,
                escapeshellarg($referer),
                escapeshellarg($file),
                escapeshellarg($url),
            ));
            if (is_file($file) && filesize($file) > 1024 && $this->looksLikePdf($file)) {
                return $file;
            }
        }
        @unlink($file);

        return null;
    }

    /** The reader endpoint only returns the file when `read=1` is present. */
    private function normalizeDownloadUrl(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['host'] ?? '') === 's1.yemen-books.com' && ($parts['path'] ?? '') === '/app/open.php') {
            parse_str($parts['query'] ?? '', $q);
            if (! isset($q['read'])) {
                $q['read'] = '1';
                $url = 'https://s1.yemen-books.com/app/open.php?'.http_build_query($q);
            }
        }

        return $url;
    }

    /** Guard against a redirect to an HTML page being stored as a "PDF". */
    private function looksLikePdf(string $file): bool
    {
        $fh = @fopen($file, 'rb');
        if (! $fh) {
            return false;
        }
        $head = (string) fread($fh, 5);
        fclose($fh);

        return str_starts_with($head, '%PDF');
    }
}
