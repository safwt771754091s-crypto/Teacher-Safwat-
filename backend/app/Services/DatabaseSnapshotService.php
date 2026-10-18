<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Creates and restores database snapshots as portable SQL dumps.
 *
 * The dump is gzip-compressed and written next to a small JSON sidecar with
 * metadata (date, driver, table/row counts, sha256), so a snapshot is
 * self-describing and can be verified before restoring.
 *
 * Drivers:
 *  - sqlite: dumped in pure PHP (no external binary needed) - works anywhere
 *    PHP runs, including the demo SQLite database.
 *  - pgsql / mysql: dumped with pg_dump / mysqldump (the tools the production
 *    database ships with); a clear error is raised when the binary is missing.
 */
class DatabaseSnapshotService
{
    public function directory(): string
    {
        return (string) config('platform.db_snapshots.path', storage_path('app/db-snapshots'));
    }

    /** @return array<string, mixed> metadata for the new snapshot */
    public function create(?string $directory = null, ?string $label = null): array
    {
        $directory = $directory ?: $this->directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('تعذّر إنشاء مجلد اللقطات: ' . $directory);
        }

        $sql = $this->dump();
        $stamp = gmdate('Ymd-His');
        $name = 'db_snapshot_' . $stamp . '.sql.gz';
        $path = rtrim($directory, '/') . '/' . $name;

        if (@file_put_contents($path, gzencode($sql, 9)) === false) {
            throw new RuntimeException('تعذّر كتابة اللقطة: ' . $path);
        }

        $meta = [
            'name' => $name,
            'label' => $label,
            'created_at' => gmdate('c'),
            'driver' => $this->driver(),
            'database' => (string) (config('database.connections.' . $this->driver() . '.database') ?? ''),
            'bytes' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'tables' => $this->tableCount($sql),
            'rows' => $this->rowCount($sql),
        ];

        @file_put_contents($path . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $meta;
    }

    /** @return list<array<string, mixed>> newest first */
    public function list(?string $directory = null): array
    {
        $directory = $directory ?: $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        $out = [];
        foreach ((array) glob(rtrim($directory, '/') . '/*.sql.gz') as $file) {
            $sidecar = $file . '.json';
            $meta = is_file($sidecar)
                ? (json_decode((string) file_get_contents($sidecar), true) ?: [])
                : [];
            $out[] = [
                'name' => basename($file),
                'bytes' => filesize($file) ?: 0,
                'created_at' => $meta['created_at'] ?? gmdate('c', (int) filemtime($file)),
                'label' => $meta['label'] ?? null,
                'driver' => $meta['driver'] ?? null,
                'sha256' => $meta['sha256'] ?? (hash_file('sha256', $file) ?: ''),
                'tables' => $meta['tables'] ?? null,
                'rows' => $meta['rows'] ?? null,
            ];
        }

        usort($out, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $out;
    }

    /** Resolve a snapshot file by name, refusing any path traversal. */
    public function path(string $name, ?string $directory = null): ?string
    {
        if ($name === '' || basename($name) !== $name || ! str_ends_with($name, '.sql.gz')) {
            return null;
        }
        $path = rtrim($directory ?: $this->directory(), '/') . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /** Delete all but the newest $keep snapshots. Returns the names removed. */
    public function prune(int $keep, ?string $directory = null): array
    {
        $removed = [];
        $list = $this->list($directory);
        foreach (array_slice($list, max(0, $keep)) as $snap) {
            $path = $this->path($snap['name'], $directory);
            if ($path) {
                @unlink($path);
                @unlink($path . '.json');
                $removed[] = $snap['name'];
            }
        }

        return $removed;
    }

    /**
     * Restore a snapshot into the configured database. Destructive: the dump
     * drops and recreates the objects it contains.
     */
    public function restore(string $file): void
    {
        if (! is_file($file)) {
            throw new RuntimeException('ملف اللقطة غير موجود: ' . $file);
        }
        $raw = str_ends_with($file, '.gz') ? (string) gzdecode((string) file_get_contents($file)) : (string) file_get_contents($file);
        $driver = $this->driver();

        if ($driver === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            // The dump carries its own BEGIN/COMMIT for external use; drop them
            // so we can wrap execution ourselves without nesting transactions.
            $statements = array_values(array_filter(
                $this->statements($raw),
                fn ($s) => ! in_array(strtoupper(trim($s)), ['BEGIN TRANSACTION', 'BEGIN', 'COMMIT'], true)
            ));

            $ownTransaction = DB::connection()->transactionLevel() === 0;
            if ($ownTransaction) {
                $pdo->exec('BEGIN');
            }
            try {
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                }
                if ($ownTransaction) {
                    $pdo->exec('COMMIT');
                }
            } catch (Throwable $e) {
                if ($ownTransaction) {
                    $pdo->exec('ROLLBACK');
                }
                throw $e;
            }

            return;
        }

        if ($driver === 'pgsql') {
            $this->pipeToProcess($this->pgsqlCommand(restore: true), $raw);

            return;
        }

        if ($driver === 'mysql') {
            $this->pipeToProcess($this->mysqlCommand(restore: true), $raw);

            return;
        }

        throw new RuntimeException('الاستعادة غير مدعومة للمشغّل: ' . $driver);
    }

    public function driver(): string
    {
        return (string) config('database.default');
    }

    /** Produce the raw SQL dump for the configured connection. */
    public function dump(): string
    {
        $driver = $this->driver();

        return match ($driver) {
            'sqlite' => $this->dumpSqlite(),
            'pgsql' => $this->dumpPgsql(),
            'mysql', 'mariadb' => $this->dumpMysql(),
            default => throw new RuntimeException('لقطات قاعدة البيانات غير مدعومة للمشغّل: ' . $driver),
        };
    }

    private function dumpSqlite(): string
    {
        $pdo = DB::connection()->getPdo();
        $quote = fn (string $n): string => $this->quoteIdentifier($n, 'sqlite');
        $lines = [
            '-- Snapshot: ' . gmdate('c'),
            '-- Driver: sqlite',
            'PRAGMA foreign_keys=OFF;',
            'BEGIN TRANSACTION;',
        ];

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $views = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='view' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        // Order tables parents-before-children so the dump can be replayed even
        // with foreign keys enforced (some hosts cannot disable them).
        $ordered = $this->sqliteTableOrder($pdo, array_map('strval', $tables));

        // Drop children first, then parents, then views.
        foreach ($views as $view) {
            $lines[] = 'DROP VIEW IF EXISTS ' . $quote((string) $view) . ';';
        }
        foreach (array_reverse($ordered) as $table) {
            $lines[] = 'DROP TABLE IF EXISTS ' . $quote($table) . ';';
        }

        // Tables (parents first), then indexes and triggers.
        foreach ($ordered as $table) {
            $create = $pdo->query(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table)
            )->fetchColumn();
            if ($create) {
                $lines[] = rtrim((string) $create, ';') . ';';
            }
        }
        $secondary = $pdo->query(
            "SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' AND type IN ('index','trigger') ORDER BY type, name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($secondary as $sql) {
            $lines[] = rtrim((string) $sql, ';') . ';';
        }

        foreach ($ordered as $table) {
            $quoted = $quote($table);
            $rows = $pdo->query('SELECT * FROM ' . $quoted)->fetchAll(\PDO::FETCH_ASSOC);
            if (! $rows) {
                continue;
            }
            $columns = array_keys($rows[0]);
            $columnList = implode(', ', array_map(fn ($c) => $quote((string) $c), $columns));
            foreach ($rows as $row) {
                $values = implode(', ', array_map(fn ($v) => $this->quoteValue($pdo, $v), array_values($row)));
                $lines[] = "INSERT INTO {$quoted} ({$columnList}) VALUES ({$values});";
            }
        }

        $lines[] = 'COMMIT;';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Topologically sort SQLite tables so every parent precedes its children.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function sqliteTableOrder(\PDO $pdo, array $tables): array
    {
        $known = array_fill_keys($tables, true);
        $parents = [];
        foreach ($tables as $table) {
            $parents[$table] = [];
            $list = $pdo->query('PRAGMA foreign_key_list(' . $this->quoteIdentifier($table, 'sqlite') . ')');
            foreach ($list as $fk) {
                $parent = (string) ($fk['table'] ?? '');
                if ($parent !== $table && isset($known[$parent]) && ! in_array($parent, $parents[$table], true)) {
                    $parents[$table][] = $parent;
                }
            }
        }

        $order = [];
        $remaining = array_fill_keys($tables, true);
        $progress = true;
        while ($remaining && $progress) {
            $progress = false;
            foreach (array_keys($remaining) as $table) {
                $ready = true;
                foreach ($parents[$table] as $parent) {
                    if (isset($remaining[$parent])) {
                        $ready = false;
                        break;
                    }
                }
                if ($ready) {
                    $order[] = $table;
                    unset($remaining[$table]);
                    $progress = true;
                }
            }
        }
        // Cycles (self-referencing chains) fall back to the remaining order.
        foreach (array_keys($remaining) as $table) {
            $order[] = $table;
        }

        return $order;
    }

    private function dumpPgsql(): string
    {
        $sql = $this->runProcess($this->pgsqlCommand());

        return "-- Snapshot: " . gmdate('c') . "\n-- Driver: pgsql\n" . $sql;
    }

    private function dumpMysql(): string
    {
        $sql = $this->runProcess($this->mysqlCommand());

        return "-- Snapshot: " . gmdate('c') . "\n-- Driver: mysql\n" . $sql;
    }

    /** @return list<string> */
    private function pgsqlCommand(bool $restore = false): array
    {
        $cfg = config('database.connections.pgsql');
        $cmd = [
            'pg_dump',
            '--no-owner',
            '--no-privileges',
            '--clean',
            '--if-exists',
            '--host=' . ($cfg['host'] ?? '127.0.0.1'),
            '--port=' . ($cfg['port'] ?? '5432'),
            '--username=' . ($cfg['username'] ?? 'postgres'),
            '--dbname=' . ($cfg['database'] ?? 'postgres'),
        ];
        if ($restore) {
            $cmd = ['psql', '--host=' . ($cfg['host'] ?? '127.0.0.1'), '--port=' . ($cfg['port'] ?? '5432'),
                '--username=' . ($cfg['username'] ?? 'postgres'), '--dbname=' . ($cfg['database'] ?? 'postgres'),
                '--quiet', '--set', 'ON_ERROR_STOP=1'];
        }

        return $cmd;
    }

    /** @return list<string> */
    private function mysqlCommand(bool $restore = false): array
    {
        $cfg = config('database.connections.mysql');
        $base = [
            '--host=' . ($cfg['host'] ?? '127.0.0.1'),
            '--port=' . ($cfg['port'] ?? '3306'),
            '--user=' . ($cfg['username'] ?? 'root'),
            '--single-transaction',
        ];

        return $restore
            ? ['mysql', ...$base, (string) ($cfg['database'] ?? '')]
            : ['mysqldump', ...$base, (string) ($cfg['database'] ?? '')];
    }

    private function runProcess(array $command): string
    {
        $env = $this->processEnv();
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($command, $descriptors, $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new RuntimeException('تعذّر تشغيل: ' . implode(' ', $command) . ' (الأداة غير مثبّتة؟)');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new RuntimeException('فشل ' . $command[0] . ' (رمز ' . $code . '): ' . trim((string) $err));
        }

        return (string) $out;
    }

    private function pipeToProcess(array $command, string $stdin): void
    {
        $env = $this->processEnv();
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($command, $descriptors, $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new RuntimeException('تعذّر تشغيل: ' . implode(' ', $command) . ' (الأداة غير مثبّتة؟)');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new RuntimeException('فشل ' . $command[0] . ' (رمز ' . $code . '): ' . trim((string) $err));
        }
    }

    /** @return array<string, string> */
    private function processEnv(): array
    {
        $env = getenv();
        $driver = $this->driver();
        $cfg = config('database.connections.' . $driver);
        $password = (string) ($cfg['password'] ?? '');
        if ($password !== '') {
            if ($driver === 'pgsql') {
                $env['PGPASSWORD'] = $password;
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                $env['MYSQL_PWD'] = $password;
            }
        }

        return $env;
    }

    private function quoteIdentifier(string $name, string $driver): string
    {
        $quote = $driver === 'mysql' || $driver === 'mariadb' ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $name) . $quote;
    }

    private function quoteValue(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $pdo->quote((string) $value);
    }

    /**
     * Split a SQL script into executable statements, ignoring semicolons that
     * are inside single-quoted string literals (doubled '' = escaped quote) and
     * skipping -- line comments. Needed for the sqlite restore path.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $out = [];
        $buffer = '';
        $len = strlen($sql);
        $inString = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($inString) {
                $buffer .= $ch;
                if ($ch === "'") {
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $buffer .= "'";
                        $i++;
                    } else {
                        $inString = false;
                    }
                }
                continue;
            }

            if ($ch === "'") {
                $inString = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($ch === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $out[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $out[] = $statement;
        }

        return $out;
    }

    private function tableCount(string $sql): int
    {
        return (int) preg_match_all('/CREATE TABLE\s+/i', $sql);
    }

    private function rowCount(string $sql): int
    {
        return (int) preg_match_all('/^INSERT INTO\s+/im', $sql);
    }
}
