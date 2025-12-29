<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\Models\DatabaseBackup;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class DatabaseRestoreService
    implements DatabaseRestore
{
    public function restore(DatabaseBackup $backup): void
    {
        $conn = DB::connection();
        $driver = $conn->getDriverName();

        if ($backup->driver !== $driver) {
            throw new \RuntimeException("Backup driver '{$backup->driver}' does not match current driver '{$driver}'.");
        }

        $fullPath = storage_path($backup->storage_path);
        if (! File::exists($fullPath)) {
            throw new \RuntimeException('Backup file not found on disk.');
        }

        if ($driver === 'sqlite') {
            $this->restoreSqlite($fullPath);
            return;
        }

        if ($driver === 'mysql') {
            $this->restoreMysql($conn, $fullPath);
            return;
        }

        throw new \RuntimeException("Unsupported database driver for restore: {$driver}");
    }

    private function restoreSqlite(string $backupPath): void
    {
        $sqlitePath = database_path('database.sqlite');
        File::copy($backupPath, $sqlitePath);
    }

    private function restoreMysql(ConnectionInterface $conn, string $backupPath): void
    {
        $database = (string) config('database.connections.mysql.database');
        $host = (string) config('database.connections.mysql.host');
        $port = (string) config('database.connections.mysql.port', '3306');
        $username = (string) config('database.connections.mysql.username');
        $password = (string) config('database.connections.mysql.password');

        if ($database === '' || $host === '' || $username === '') {
            throw new \RuntimeException('MySQL restore requires database connection settings (host, database, username).');
        }

        $fh = fopen($backupPath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Could not open backup file for restore.');
        }

        try {
            $env = array_merge($_ENV, $_SERVER, [
                'MYSQL_PWD' => $password,
            ]);

            $process = new Process([
                'mysql',
                '--host='.$host,
                '--port='.$port,
                '--user='.$username,
                $database,
            ], null, $env);

            $process->setTimeout(null);
            $process->setInput($fh);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } finally {
            fclose($fh);
        }
    }
}


