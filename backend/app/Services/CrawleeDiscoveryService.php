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
        return base_path('tools/crawlee_service/crawl_books.py');
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
        $cmd = sprintf(
            'timeout %d %s %s --url %s --max-pages %d 2>/dev/null',
            $timeout,
            escapeshellarg($python),
            escapeshellarg($this->scriptPath()),
            escapeshellarg($url),
            max(1, $maxPages),
        );

        $raw = (string) @shell_exec($cmd);
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
     * Best-effort: attach the first discovered PDF to a book that has none, so
     * the pipeline can then extract it. Returns the URL it attached, or null.
     */
    public function attachSourcePdf(Textbook $textbook): ?string
    {
        if ($textbook->file_path || $textbook->source_url) {
            return null; // already has a source; never override a human's choice
        }
        $index = $textbook->source_url ?: null;
        if (! $index) {
            return null;
        }

        $found = $this->discover($index);
        foreach ($found['links'] ?? [] as $link) {
            if (! empty($link['is_pdf']) && filter_var($link['url'], FILTER_VALIDATE_URL)) {
                $textbook->update(['source_url' => $link['url']]);

                return $link['url'];
            }
        }

        return null;
    }
}
