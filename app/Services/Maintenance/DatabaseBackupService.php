<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class DatabaseBackupService
{
    /**
     * @return array{driver: string, path: string, filename: string}
     */
    public function backup(): array
    {
        $conn = DB::connection();
        $driver = $conn->getDriverName();

        $timestamp = now()->format('Ymd-His');
        $prefix = Str::snake((string) config('app.name', 'pricing_tool'));
        if ($prefix === '') {
            $prefix = 'pricing_tool';
        }

        $dir = storage_path('backups');
        File::ensureDirectoryExists($dir);

        if ($driver === 'sqlite') {
            return $this->backupSqlite($timestamp, $prefix);
        }

        if ($driver === 'mysql') {
            return $this->backupMysql($conn, $timestamp, $prefix);
        }

        throw new \RuntimeException("Unsupported database driver for backup: {$driver}");
    }

    /**
     * @return array{driver: string, path: string, filename: string}
     */
    private function backupSqlite(string $timestamp, string $prefix): array
    {
        $sqlitePath = database_path('database.sqlite');
        if (! File::exists($sqlitePath)) {
            throw new \RuntimeException("SQLite file not found at {$sqlitePath}");
        }

        $filename = "{$prefix}-{$timestamp}.sqlite";
        $dest = storage_path("backups/{$filename}");

        File::copy($sqlitePath, $dest);

        return [
            'driver' => 'sqlite',
            'path' => $dest,
            'filename' => $filename,
        ];
    }

    /**
     * @return array{driver: string, path: string, filename: string}
     */
    private function backupMysql(ConnectionInterface $conn, string $timestamp, string $prefix): array
    {
        $database = (string) config('database.connections.mysql.database');
        $host = (string) config('database.connections.mysql.host');
        $port = (string) config('database.connections.mysql.port', '3306');
        $username = (string) config('database.connections.mysql.username');
        $password = (string) config('database.connections.mysql.password');

        if ($database === '' || $host === '' || $username === '') {
            throw new \RuntimeException('MySQL backup requires database connection settings (host, database, username).');
        }

        $filename = "{$prefix}-{$timestamp}.sql";
        $dest = storage_path("backups/{$filename}");

        // Use MYSQL_PWD env var to avoid exposing the password in process args.
        $env = array_merge($_ENV, $_SERVER, [
            'MYSQL_PWD' => $password,
        ]);

        $process = new Process([
            'mysqldump',
            '--no-tablespaces',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            '--result-file='.$dest,
            $database,
        ], null, $env);

        // Backups can be slow on some machines; don't time out.
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        if (! File::exists($dest)) {
            throw new \RuntimeException('MySQL backup failed: output file was not created.');
        }

        return [
            'driver' => 'mysql',
            'path' => $dest,
            'filename' => $filename,
        ];
    }
}




