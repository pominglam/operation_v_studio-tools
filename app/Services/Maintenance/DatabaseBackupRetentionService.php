<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\DAL\Maintenance\DatabaseBackupRepository;
use App\DTOs\Maintenance\DatabaseBackupPurgeResult;
use App\Models\DatabaseBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

final class DatabaseBackupRetentionService
{
    public function __construct(
        private readonly DatabaseBackupRepository $backups,
    ) {}

    public function purge(bool $dryRun = false): DatabaseBackupPurgeResult
    {
        $all = $this->backups->listAllOrderedByNewest();
        $keepUuids = $this->selectUuidsToKeep($all);
        $toPurge = $all->reject(fn (DatabaseBackup $b): bool => in_array($b->uuid, $keepUuids, true));

        $purgedUuids = [];
        if (! $dryRun) {
            foreach ($toPurge as $backup) {
                $this->deleteArtifact($backup);
                $this->backups->deleteByUuid($backup->uuid);
                $purgedUuids[] = $backup->uuid;
            }
        } else {
            $purgedUuids = $toPurge->pluck('uuid')->all();
        }

        return new DatabaseBackupPurgeResult(
            totalBefore: $all->count(),
            keptCount: count($keepUuids),
            purgedCount: count($purgedUuids),
            keptUuids: $keepUuids,
            purgedUuids: $purgedUuids,
            dryRun: $dryRun,
        );
    }

    /**
     * @return list<string>
     */
    public function selectUuidsToKeep(Collection $backups): array
    {
        if ($backups->isEmpty()) {
            return [];
        }

        $recentDays = max(1, (int) config('database_backup.retention.recent_days', 14));
        $weeklyDays = max($recentDays, (int) config('database_backup.retention.weekly_days', 180));
        $minimumCount = max(1, (int) config('database_backup.retention.minimum_count', 5));

        $now = Carbon::now();
        $recentCutoff = $now->copy()->subDays($recentDays);
        $weeklyCutoff = $now->copy()->subDays($weeklyDays);

        $keep = [];

        foreach ($backups as $backup) {
            $created = Carbon::parse($backup->created_at);
            if ($created->greaterThanOrEqualTo($recentCutoff)) {
                $keep[$backup->uuid] = true;
            }
        }

        $older = $backups->filter(function (DatabaseBackup $backup) use ($recentCutoff, $weeklyCutoff): bool {
            $created = Carbon::parse($backup->created_at);

            return $created->lessThan($recentCutoff) && $created->greaterThanOrEqualTo($weeklyCutoff);
        });

        /** @var array<string, DatabaseBackup> $bestPerWeek */
        $bestPerWeek = [];
        foreach ($older as $backup) {
            $created = Carbon::parse($backup->created_at);
            $weekKey = $created->isoFormat('GGGG-WW');
            if (! isset($bestPerWeek[$weekKey]) || $created->greaterThan(Carbon::parse($bestPerWeek[$weekKey]->created_at))) {
                $bestPerWeek[$weekKey] = $backup;
            }
        }

        foreach ($bestPerWeek as $backup) {
            $keep[$backup->uuid] = true;
        }

        foreach ($backups->take($minimumCount) as $backup) {
            $keep[$backup->uuid] = true;
        }

        return array_keys($keep);
    }

    private function deleteArtifact(DatabaseBackup $backup): void
    {
        $path = storage_path($backup->storage_path);
        if (File::isFile($path)) {
            File::delete($path);
        }
    }
}
