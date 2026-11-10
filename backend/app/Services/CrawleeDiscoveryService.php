<?php

namespace App\Services;

use App\Models\Textbook;
use Illuminate\Support\Facades\Log;

/**
 * Optional helper that finds *source* material for a book using the Crawlee
 * helper script (tools/crawlee_service/crawl_books.py).
 *
 * This never replaces the platform's own extractor: the extractor still reads
 * the PDF and OCRs it. Crawlee only answers the earlier question "where is the
 * official file". If Python or Crawlee is not installed the platform is
 * unaffected - every method here degrades to "found nothing" rather than
 * throwing, because discovery is a convenience, not a requirement.
 */
class CrawleeDiscoveryService
{
    private function scriptPath(): string
    {
        return (string) config('platform.crawlee.script', base_path('tools/crawlee_service/crawl_books.py'));
    }

    /** Remove a throwaway directory and everything in it. */
    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** True when the helper is actually runnable here. */
    public function available(): bool
    {
        if (! is_file($this->scriptPath())) {
            return false;
        }
        $python = (string) config('platform.crawlee.python', 'python3');
        $out = (string) @shell_exec(
            escapeshellarg($python).' -c "import crawlee" 2>&1'
        );

        return trim($out) === '';
    }

    /**
     * Discover candidate source links (and page text) under an index URL.
     *
     * @return array{ok:bool,links:list<array{url:string,text:string,is_pdf:bool}>,pages:list<array<string,mixed>>,error?:string}
     */
    public function discover(string $url, int $maxPages = 2): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'links' => [], 'pages' => [], 'error' => 'رابط غير صالح.'];
        }
        if (! is_file($this->scriptPath())) {
            return ['ok' => false, 'links' => [], 'pages' => [], 'error' => 'أداة الاستكشاف غير مثبّتة.'];
        }

        $python = (string) config('platform.crawlee.python', 'python3');
        $timeout = (int) config('platform.crawlee.timeout', 90);

        // Run in a throwaway working directory: Crawlee writes a storage/ folder
        // of request-queue state next to wherever it runs, and that must never
        // land in the application tree (or leak between runs).
        $workdir = sys_get_temp_dir().'/crawlee_'.uniqid();
        @mkdir($workdir, 0777, true);

        $cmd = sprintf(
            'cd %s && CRAWLEE_STORAGE_DIR=%s timeout %d %s %s --url %s --max-pages %d 2>/dev/null',
            escapeshellarg($workdir),
            escapeshellarg($workdir.'/storage'),
            $timeout,
            escapeshellarg($python),
            escapeshellarg($this->scriptPath()),
            escapeshellarg($url),
            max(1, $maxPages),
        );

        try {
            $raw = (string) @shell_exec($cmd);
        } finally {
            $this->removeDir($workdir);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! ($decoded['ok'] ?? false)) {
            Log::info('Crawlee discovery found nothing', ['url' => $url, 'error' => $decoded['error'] ?? 'no output']);

            return ['ok' => false, 'links' => [], 'pages' => [], 'error' => $decoded['error'] ?? 'لم يُعثر على مصدر.'];
        }

        return [
            'ok' => true,
            'links' => $decoded['links'] ?? [],
            'pages' => $decoded['pages'] ?? [],
        ];
    }

    /**
     * Crawl an index page for a book and remember the candidates on the row.
     *
     * Persisting them is the point: crawling is slow and depends on the network,
     * so a reviewer who looks at the list later should not trigger another crawl.
     * The book's own source is never touched here - choosing a file is a separate,
     * explicit action.
     */
    public function discoverFor(Textbook $textbook, ?string $url = null, int $maxPages = 2): array
    {
        $url = $url ?: $textbook->discovery_url;
        if (! $url) {
            return ['ok' => false, 'links' => [], 'pages' => [], 'error' => 'لم يُحدَّد رابط صفحة الفهرس.'];
        }

        $found = $this->discover($url, $maxPages);
        if (! $found['ok']) {
            return $found;
        }

        $candidates = array_values(array_map(fn ($link) => [
            'url' => (string) ($link['url'] ?? ''),
            'text' => (string) ($link['text'] ?? ''),
            'is_pdf' => (bool) ($link['is_pdf'] ?? false),
        ], $found['links']));

        $textbook->forceFill([
            'discovery_url' => $url,
            'source_candidates' => $candidates,
        ])->save();

        return ['ok' => true, 'links' => $candidates, 'pages' => $found['pages']];
    }

    /** The best PDF among a candidate list, or null when none is a PDF. */
    public function bestPdfCandidate(array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if (! empty($candidate['is_pdf']) && filter_var($candidate['url'] ?? '', FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Attach a discovered PDF to a book that has none, so the pipeline can then
     * extract it. Crawls the book's index page when no candidate was stored yet,
     * and returns the URL it attached (or null when there is nothing to attach).
     */
    public function attachSourcePdf(Textbook $textbook): ?string
    {
        if ($textbook->file_path || $textbook->source_url) {
            return null; // already has a source; never override a human's choice
        }

        $candidates = (array) ($textbook->source_candidates ?? []);
        if ($candidates === [] && $textbook->discovery_url) {
            $found = $this->discoverFor($textbook);
            $candidates = $found['links'] ?? [];
        }

        $best = $this->bestPdfCandidate($candidates);
        if ($best === null) {
            return null;
        }

        $textbook->forceFill(['source_url' => $best['url']])->save();

        return $best['url'];
    }
}
