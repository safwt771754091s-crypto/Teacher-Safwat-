<?php

namespace App\Console\Commands;

use App\Models\Textbook;
use Illuminate\Console\Command;

/**
 * Backfill `textbooks.source_url` from an import manifest.
 *
 * Books imported before the source URL was persisted have no way to re-render
 * their pages on demand. This matches each existing textbook to its manifest
 * entry — first by exact title, then by (grade, subject, term) for the curated
 * sample books — and stores the URL, so the in-app page viewer works for the
 * whole catalogue without re-importing.
 */
class BackfillTextbookSources extends Command
{
    protected $signature = 'textbooks:backfill-sources {manifest : Path to the JSON manifest}';

    protected $description = 'Fill textbooks.source_url from an import manifest';

    /** Manifest subject name => the canonical subject name used in the DB. */
    private const SUBJECT_ALIASES = [
        'اللغة العربية' => 'لغتي الجميلة (العربية)',
        'اللغة العربية (لغتي الجميلة)' => 'لغتي الجميلة (العربية)',
        'لغتي الجميلة' => 'لغتي الجميلة (العربية)',
        'الحاسوب' => 'مهارات الحاسوب',
        'الفلسفة وعلم النفس' => 'الفلسفة',
        'التربية الوطنية' => 'المواطنة',
    ];

    public function handle(): int
    {
        $manifest = json_decode((string) file_get_contents((string) $this->argument('manifest')), true);
        if (! is_array($manifest)) {
            $this->error('Invalid manifest JSON.');

            return self::FAILURE;
        }

        $byTitle = [];
        $byKey = [];
        foreach ($manifest as $entry) {
            if (empty($entry['title']) || empty($entry['url'])) {
                continue;
            }
            $byTitle[$entry['title']] = $entry['url'];

            $key = $this->key((int) ($entry['grade_level'] ?? 0), (string) ($entry['subject'] ?? ''), (string) ($entry['term'] ?? 'first'));
            $byKey[$key] ??= $entry['url'];
        }

        $byTitleCount = 0;
        $byKeyCount = 0;
        foreach (Textbook::whereNull('source_url')->with('subject.grade')->get() as $textbook) {
            $url = $byTitle[$textbook->title] ?? null;
            if ($url) {
                $byTitleCount++;
            } else {
                $level = (int) ($textbook->subject->grade->level ?? 0);
                $url = $byKey[$this->key($level, (string) ($textbook->subject->name ?? ''), (string) $textbook->term)] ?? null;
                if ($url) {
                    $byKeyCount++;
                }
            }

            if ($url) {
                $textbook->forceFill(['source_url' => $url])->save();
            }
        }

        $this->info("Backfilled {$byTitleCount} by title + {$byKeyCount} by grade/subject.");

        return self::SUCCESS;
    }

    private function key(int $level, string $subject, string $term): string
    {
        $subject = preg_replace('/\([^)]*\)/u', ' ', $subject) ?? $subject;
        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);
        $subject = self::SUBJECT_ALIASES[$subject] ?? $subject;

        return $level . '|' . $subject . '|' . $term;
    }
}
