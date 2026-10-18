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
        {action=create : create | list | restore | upload | remote-list}
        {--name= : snapshot file name (for restore/upload)}
        {--label= : optional comment stored with the snapshot}
        {--keep=0 : after create, prune so only the newest N remain (0 = keep all)}
        {--upload : after create, also upload the snapshot to the remote disk}
        {--remote : for restore, download the snapshot from the remote disk if missing}
        {--json : emit machine-readable JSON}';

    protected $description = 'Create, list, upload, or restore portable database snapshots.';

    public function handle(DatabaseSnapshotService $snapshots): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'create' => $this->create($snapshots),
                'list' => $this->list($snapshots),
                'restore' => $this->restore($snapshots),
                'upload' => $this->upload($snapshots),
                'remote-list' => $this->remoteList($snapshots),
                default => $this->invalid($action),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function create(DatabaseSnapshotService $snapshots): int
    {
        $meta = $this->option('upload')
            ? $snapshots->createAndUpload(label: $this->option('label') ?: null)
            : $snapshots->create(label: $this->option('label') ?: null);
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
        if (! empty($meta['remote'])) {
            $this->line('التخزين البعيد: ' . $meta['remote']);
        }
        if ($removed) {
            $this->warn('حُذفت لقطات قديمة: ' . implode(', ', $removed));
        }

        return self::SUCCESS;
    }

    private function upload(DatabaseSnapshotService $snapshots): int
    {
        if (! $snapshots->uploadsEnabled()) {
            $this->error('التخزين البعيد غير مُفعّل (اضبط DB_SNAPSHOT_REMOTE=1).');

            return self::FAILURE;
        }

        $name = (string) $this->option('name');
        if ($name === '') {
            $latest = $snapshots->list()[0]['name'] ?? null;
            if (! $latest) {
                $this->error('لا توجد لقطة محلية لرفعها.');

                return self::FAILURE;
            }
            $name = $latest;
        }

        $target = $snapshots->upload($name);
        if ($this->option('json')) {
            $this->line(json_encode(['uploaded' => $name, 'remote' => $target], JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('تم رفع اللقطة إلى: ' . $target);
        }

        return self::SUCCESS;
    }

    private function remoteList(DatabaseSnapshotService $snapshots): int
    {
        if (! $snapshots->uploadsEnabled()) {
            $this->error('التخزين البعيد غير مُفعّل (اضبط DB_SNAPSHOT_REMOTE=1).');

            return self::FAILURE;
        }

        $items = $snapshots->listRemote();
        if ($this->option('json')) {
            $this->line(json_encode(['snapshots' => $items, 'target' => $snapshots->remoteTarget()], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (! $items) {
            $this->warn('لا توجد لقطات على التخزين البعيد.');

            return self::SUCCESS;
        }

        $this->table(
            ['الملف', 'التاريخ', 'الجداول', 'الصفوف', 'الحجم'],
            array_map(fn ($s) => [
                $s['name'], $s['created_at'],
                $s['tables'] ?? '-', $s['rows'] ?? '-', $this->human((int) $s['bytes']),
            ], $items)
        );

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
        $path = $this->option('remote')
            ? $snapshots->localPath($name)
            : $snapshots->path($name);
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
