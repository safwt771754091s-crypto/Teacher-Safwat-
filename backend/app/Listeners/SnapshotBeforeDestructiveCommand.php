<?php

namespace App\Listeners;

use App\Services\DatabaseSnapshotService;
use Illuminate\Console\Events\CommandStarting;
use Throwable;

/**
 * Takes a database snapshot before any destructive migrate/seed command, so a
 * bad `migrate:fresh` or `db:seed` can be rolled back. Disabled in testing and
 * when DB_SNAPSHOT_BEFORE=0.
 */
class SnapshotBeforeDestructiveCommand
{
    /** Commands whose run may destroy existing data. */
    private const DESTRUCTIVE = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
        'db:seed',
    ];

    public function __construct(private readonly DatabaseSnapshotService $snapshots) {}

    public function handle(CommandStarting $event): void
    {
        if (! $this->enabled() || ! in_array($event->command, self::DESTRUCTIVE, true)) {
            return;
        }

        try {
            if ($this->snapshots->tableTotal() < 1) {
                return;
            }
            $this->snapshots->create(label: 'auto pre-'.$event->command);

            $keep = (int) config('platform.db_snapshots.pre_command_keep', 5);
            if ($keep > 0) {
                $this->snapshots->prune($keep, labelPrefix: 'auto pre-');
            }
        } catch (Throwable $e) {
            // A snapshot failure must never block the command the user asked for.
            $event->output->writeln('<comment>DB snapshot skipped: '.$e->getMessage().'</comment>');
        }
    }

    protected function enabled(): bool
    {
        return filter_var(config('platform.db_snapshots.before_destructive', true), FILTER_VALIDATE_BOOL);
    }
}
