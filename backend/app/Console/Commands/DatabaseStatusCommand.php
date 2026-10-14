<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Verify the configured database (Supabase PostgreSQL in production) is
 * reachable and migrated. Useful as a deploy smoke test:
 *
 *   php artisan platform:db-status
 *
 * Exits non-zero when the connection fails or the schema is missing, so a
 * deployment pipeline can gate on it. The password is never printed.
 */
class DatabaseStatusCommand extends Command
{
    protected $signature = 'platform:db-status {--tables : Also list the app tables that are present}';

    protected $description = 'Check database connectivity, PostgreSQL version, and migration status.';

    public function handle(): int
    {
        $conn = config('database.default');
        $cfg = config("database.connections.{$conn}");

        $this->line('الاتصال: ' . $conn . ' (' . ($cfg['driver'] ?? '?') . ')');
        $this->line('الخادم: ' . ($cfg['host'] ?? '-') . ':' . ($cfg['port'] ?? '-'));
        $this->line('قاعدة البيانات: ' . ($cfg['database'] ?? '-'));
        $this->line('المستخدم: ' . ($cfg['username'] ?? '-'));
        if (! empty($cfg['sslmode'])) {
            $this->line('SSL: ' . $cfg['sslmode']);
        }

        try {
            $version = $this->serverVersion($conn, $cfg['driver'] ?? $conn);
            $this->info('تم الاتصال بقاعدة البيانات.');
            if ($version !== null) {
                $this->line('الإصدار: ' . strtok((string) $version, ','));
            }
        } catch (Throwable $e) {
            $this->error('تعذّر الاتصال بقاعدة البيانات: ' . $e->getMessage());

            return self::FAILURE;
        }

        $expected = [
            'users', 'grades', 'subjects', 'textbooks', 'textbook_pages', 'lessons',
            'lesson_sections', 'exercises', 'exercise_options', 'summaries',
            'audio_assets', 'student_profiles', 'learning_progress', 'attempts',
            'game_activities', 'game_sessions', 'achievements', 'student_achievements',
            'parent_links', 'teacher_assignments', 'ai_usage_logs', 'settings',
            'agents', 'agent_runs',
        ];

        $missing = [];
        $present = [];
        foreach ($expected as $table) {
            try {
                if (Schema::hasTable($table)) {
                    $present[] = $table;
                } else {
                    $missing[] = $table;
                }
            } catch (Throwable $e) {
                $this->error('تعذّر قراءة جداول قاعدة البيانات: ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        $this->line('الجداول الموجودة: ' . count($present) . ' / المتوقعة: ' . count($expected));

        if (! empty($missing)) {
            $this->warn('جداول ناقصة: ' . implode(', ', $missing));
            $this->line('شغّل: php artisan migrate --force');

            return self::FAILURE;
        }

        $this->info('المخطط مكتمل وجاهز.');
        if ($this->option('tables')) {
            sort($present);
            $this->line(implode("\n", $present));
        }

        return self::SUCCESS;
    }

    /**
     * Best-effort server version string, using the driver-appropriate query so
     * the same command works on PostgreSQL (production) and SQLite (tests).
     */
    private function serverVersion(string $connection, string $driver): ?string
    {
        try {
            return match ($driver) {
                'pgsql' => DB::connection($connection)->selectOne('select version() as v')->v ?? null,
                'sqlite' => DB::connection($connection)->selectOne('select sqlite_version() as v')->v ?? null,
                'mysql', 'mariadb' => DB::connection($connection)->selectOne('select version() as v')->v ?? null,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
