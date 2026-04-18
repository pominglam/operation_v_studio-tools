<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Services\Maintenance\DatabaseBackupManagerService;
use Illuminate\Support\Facades\Storage;

final class PlamodFirstSyncBackupService
{
    private const string MARKER_PATH = 'plamod/.db_backup_created.json';

    public function __construct(
        private readonly DatabaseBackupManagerService $backups,
    ) {}

    /**
     * Ensure we have a DB backup before the first Plamod sync ever runs.
     *
     * @return array{created: bool, backup: array{driver: string, path: string, filename: string}|null}
     */
    public function ensureBackupExists(): array
    {
        $disk = Storage::disk('local');

        if ($disk->exists(self::MARKER_PATH)) {
            return ['created' => false, 'backup' => null];
        }

        $backup = $this->backups->create('Auto backup before first Plamod sync', 'system');

        $disk->put(self::MARKER_PATH, json_encode([
            'created_at' => now()->toIso8601String(),
            'backup_uuid' => $backup->uuid,
            'backup' => [
                'driver' => $backup->driver,
                'path' => storage_path($backup->storage_path),
                'filename' => $backup->filename,
            ],
        ], JSON_THROW_ON_ERROR));

        return ['created' => true, 'backup' => [
            'driver' => $backup->driver,
            'path' => storage_path($backup->storage_path),
            'filename' => $backup->filename,
        ]];
    }
}
