<?php

namespace App\Support;

/**
 * Decides whether the shared demo accounts (student/teacher/parent with the
 * password "password") may be created.
 *
 * They exist so the platform is usable the moment it boots: a login screen
 * nobody can pass is not a working deployment. They are therefore created when
 * the database is empty, and on hosts that cannot keep data at all. On a real,
 * populated production database they are never created, so no default password
 * is ever shipped there.
 */
class DemoAccounts
{
    /**
     * @param  string|null  $appEnv  current APP_ENV
     * @param  string|null  $connection  current DB_CONNECTION
     * @param  bool|null  $forced  SEED_DEMO_USERS, when the operator set it
     */
    public static function shouldSeed(?string $appEnv, ?string $connection, ?bool $forced = null): bool
    {
        if ($forced !== null) {
            return $forced;
        }

        // Local development always gets them.
        if (($appEnv ?? 'production') !== 'production') {
            return true;
        }

        // SQLite in production means the host has no persistent volume: the
        // catalogue is re-seeded on every restart and registered accounts are
        // gone, so the demo accounts are the only reliable way in.
        return ($connection ?? 'sqlite') === 'sqlite';
    }

    /**
     * The seed decision for the running instance.
     */
    public static function enabled(): bool
    {
        return (bool) config('platform.seed_demo_users');
    }
}
