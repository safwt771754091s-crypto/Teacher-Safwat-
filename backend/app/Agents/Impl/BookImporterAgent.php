<?php

namespace App\Agents\Impl;

use App\Agents\AgentContext;
use App\Agents\AgentResult;
use App\Agents\Contracts\Agent;
use Illuminate\Support\Facades\Artisan;

/**
 * Imports official Yemeni textbooks from a manifest, OCRs scanned pages and
 * drafts lessons. Runs entirely on the local machine (download + tesseract), so
 * it works with or without internet once the PDFs are cached.
 */
class BookImporterAgent implements Agent
{
    public function key(): string
    {
        return 'book-importer';
    }

    public function name(): string
    {
        return 'وكيل استيراد الكتب';
    }

    public function purpose(): string
    {
        return 'يحمّل كتب المنهج اليمني، ويستخرج نصوصها (OCR)، وينشئ الدروس المسودة.';
    }

    public function offlineCapable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return ['download', 'ocr', 'draft-lessons'];
    }

    public function run(AgentContext $context): AgentResult
    {
        $manifest = (string) $context->param('manifest', base_path('database/data/yemen_books_all.json'));
        if (! is_file($manifest)) {
            return AgentResult::needsContent('لم يُعثر على ملف الفهرس: '.$manifest);
        }

        $args = [
            'manifest' => $manifest,
            '--limit' => (int) $context->param('limit', 1),
            '--offset' => (int) $context->param('offset', 0),
            '--count' => (int) $context->param('count', 0),
            '--keep-files' => true,
        ];
        if ($grade = $context->param('grade')) {
            $args['--grade'] = (int) $grade;
        }
        if ($authority = $context->param('authority')) {
            $args['--authority'] = (string) $authority;
        }
        if ($context->param('draft_lessons', true)) {
            $args['--draft-lessons'] = true;
        }

        $exit = Artisan::call('textbooks:import', $args);
        $log = Artisan::output();

        // The command reports "Imported N textbook(s)." — surface the count.
        $imported = preg_match('/Imported (\d+) textbook/', $log, $m) ? (int) $m[1] : 0;

        if ($exit !== 0) {
            return AgentResult::error('فشل الاستيراد: '.trim($log));
        }

        return AgentResult::ok(
            ['log' => trim($log), 'imported' => $imported, 'manifest' => basename($manifest)],
            $imported,
            offline: true,
        );
    }
}
