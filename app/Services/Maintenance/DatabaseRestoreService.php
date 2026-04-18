<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\Models\DatabaseBackup;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class DatabaseRestoreService implements DatabaseRestore
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

        if (str_ends_with(strtolower($fullPath), '.zip')) {
            $this->restoreFromBundleZip($conn, $driver, $fullPath, $backup->uuid);

            return;
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

    private function restoreFromBundleZip(ConnectionInterface $conn, string $driver, string $bundlePath, string $backupUuid): void
    {
        $tmpDir = storage_path('backups/restore_tmp_'.$backupUuid.'_'.now()->format('YmdHis'));
        File::ensureDirectoryExists($tmpDir);

        try {
            $this->extractZipSafely($bundlePath, $tmpDir);

            $dbPath = $this->findBundledDbPath($tmpDir, $driver);
            if ($driver === 'sqlite') {
                $this->restoreSqlite($dbPath);
            } elseif ($driver === 'mysql') {
                $this->restoreMysql($conn, $dbPath);
            } else {
                throw new \RuntimeException("Unsupported database driver for restore: {$driver}");
            }

            $this->restoreBundledStorageApp($tmpDir);
        } finally {
            // Best-effort cleanup.
            try {
                File::deleteDirectory($tmpDir);
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    private function findBundledDbPath(string $tmpDir, string $driver): string
    {
        $dbDir = $tmpDir.'/db';
        if (! is_dir($dbDir)) {
            throw new \RuntimeException('Backup archive is missing db/ folder.');
        }

        $ext = $driver === 'sqlite' ? 'sqlite' : 'sql';
        $files = File::files($dbDir);

        foreach ($files as $f) {
            $name = strtolower($f->getFilename());
            if (str_ends_with($name, '.'.$ext)) {
                return $f->getPathname();
            }
        }

        throw new \RuntimeException('Backup archive is missing the database dump.');
    }

    private function restoreBundledStorageApp(string $tmpDir): void
    {
        $bundleStorageApp = $tmpDir.'/storage/app';
        if (! is_dir($bundleStorageApp)) {
            return;
        }

        $targetRoot = storage_path('app');
        File::ensureDirectoryExists($targetRoot);

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($bundleStorageApp, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $src = $file->getPathname();
            $rel = str_replace('\\', '/', substr($src, strlen($bundleStorageApp) + 1));
            $rel = ltrim($rel, '/');
            if ($rel === '' || str_contains($rel, '../') || str_contains($rel, '..\\')) {
                continue;
            }

            $dest = $targetRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            File::ensureDirectoryExists(dirname($dest));
            File::copy($src, $dest);
        }
    }

    private function extractZipSafely(string $zipPath, string $tmpDir): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open backup archive.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $safe = $this->sanitizeZipEntryPath($name);
                if ($safe === null) {
                    continue;
                }

                $target = $tmpDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $safe);
                File::ensureDirectoryExists(dirname($target));

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }

                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);

                    continue;
                }

                try {
                    stream_copy_to_stream($stream, $out);
                } finally {
                    fclose($out);
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function sanitizeZipEntryPath(string $name): ?string
    {
        $n = str_replace('\\', '/', $name);
        $n = ltrim($n, '/');
        if ($n === '' || str_ends_with($n, '/')) {
            return null;
        }
        if (str_contains($n, '../') || str_contains($n, '..\\')) {
            return null;
        }
        if (strlen($n) > 240) {
            return null;
        }

        return $n;
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
