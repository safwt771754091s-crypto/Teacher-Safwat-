<?php

namespace App\Console\Commands;

use App\Services\DatabaseSnapshotService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Database snapshots (portable SQL dumps) for backup and restore.
 *
 *   php artisan platform:db-snapshot                       # create
 *   php artisan platform:db-snapshot create --label="..."  # create with a comment
 *   php artisan platform:db-snapshot create --keep=30      # create, keep newest 30
 *   php artisan platform:db-snapshot list                  # list snapshots
 *   php artisan platform:db-snapshot restore --name=db_snapshot_2026-10-18-060000.sql.gz
 *
 * The CI workflow runs `create` on every push to main and uploads the dump as
 * a build artifact, so a restorable copy survives a lost database.
 */
class DatabaseSnapshotCommand extends Command
{
    protected $signature = 'platform:db-snapshot
        {action=create : create | list | restore}
        {--name= : snapshot file name (for restore)}
        {--label= : optional comment stored with the snapshot}
        {--keep=0 : after create, prune so only the newest N remain (0 = keep all)}
        {--json : emit machine-readable JSON}';

    protected $description = 'Create, list, or restore portable database snapshots.';

    public function handle(DatabaseSnapshotService $snapshots): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'create' => $this->create($snapshots),
                'list' => $this->list($snapshots),
                'restore' => $this->restore($snapshots),
                default => $this->invalid($action),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function create(DatabaseSnapshotService $snapshots): int
    {
        $meta = $snapshots->create(label: $this->option('label') ?: null);
        $removed = [];
        if ((int) $this->option('keep') > 0) {
            $removed = $snapshots->prune((int) $this->option('keep'));
        }

        if ($this->option('json')) {
            $this->line(json_encode(['snapshot' => $meta, 'pruned' => $removed], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('تم إنشاء لقطة قاعدة البيانات.');
        $this->line('الملف: ' . $meta['name']);
        $this->line('التاريخ: ' . $meta['created_at']);
        $this->line('المشغّل: ' . $meta['driver']);
        $this->line('الجداول: ' . $meta['tables'] . ' — الصفوف: ' . $meta['rows']);
        $this->line('الحجم: ' . $this->human((int) $meta['bytes']));
        $this->line('sha256: ' . $meta['sha256']);
        if ($removed) {
            $this->warn('حُذفت لقطات قديمة: ' . implode(', ', $removed));
        }

        return self::SUCCESS;
    }

    private function list(DatabaseSnapshotService $snapshots): int
    {
        $items = $snapshots->list();

        if ($this->option('json')) {
            $this->line(json_encode(['snapshots' => $items], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (! $items) {
            $this->warn('لا توجد لقطات بعد.');

            return self::SUCCESS;
        }

        $this->table(
            ['الملف', 'التاريخ', 'المشغّل', 'الجداول', 'الصفوف', 'الحجم'],
            array_map(fn ($s) => [
                $s['name'], $s['created_at'], $s['driver'] ?? '-',
                $s['tables'] ?? '-', $s['rows'] ?? '-', $this->human((int) $s['bytes']),
            ], $items)
        );

        return self::SUCCESS;
    }

    private function restore(DatabaseSnapshotService $snapshots): int
    {
        $name = (string) $this->option('name');
        $path = $snapshots->path($name);
        if (! $path) {
            $this->error('لم يُعثر على اللقطة: ' . $name);

            return self::FAILURE;
        }

        if (! $this->option('json') && ! $this->confirm('الاستعادة ستستبدل بيانات الجداول الموجودة. هل تريد المتابعة؟', false)) {
            $this->warn('أُلغيت الاستعادة.');

            return self::SUCCESS;
        }

        $snapshots->restore($path);
        if ($this->option('json')) {
            $this->line(json_encode(['restored' => $name], JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('تمت استعادة اللقطة: ' . $name);
        }

        return self::SUCCESS;
    }

    private function invalid(string $action): int
    {
        $this->error('إجراء غير معروف: ' . $action . ' (استخدم create أو list أو restore)');

        return self::FAILURE;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1) . ' ' . $units[$i];
    }
}
