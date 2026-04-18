<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\DAL\Maintenance\DatabaseBackupRepository;
use App\Models\DatabaseBackup;
use Illuminate\Support\Facades\File;

final class DatabaseBackupManagerService implements DatabaseBackupManager
{
    public function __construct(
        private readonly DatabaseBackupService $backup,
        private readonly DatabaseBackupRepository $backups,
    ) {}

    /**
     * @return array<int, DatabaseBackup>
     */
    public function listRecent(int $limit = 100): array
    {
        return $this->backups->listRecent($limit)->all();
    }

    public function create(string $description, string $createdBy = 'manual'): DatabaseBackup
    {
        $description = trim($description);
        if ($description === '') {
            $description = 'Manual backup';
        }

        $createdBy = trim($createdBy);
        if ($createdBy === '') {
            $createdBy = 'manual';
        }

        $result = $this->backup->backup();
        $path = $result['path'];
        $filename = $result['filename'];

        $size = File::exists($path) ? File::size($path) : null;

        $backup = new DatabaseBackup([
            'driver' => $result['driver'],
            'filename' => $filename,
            'storage_path' => "backups/{$filename}",
            'description' => $description,
            'created_by' => $createdBy,
            'size_bytes' => $size !== false ? $size : null,
        ]);

        return $this->backups->create($backup);
    }
}
