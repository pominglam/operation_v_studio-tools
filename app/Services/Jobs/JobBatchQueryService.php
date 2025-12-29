<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use Illuminate\Support\Facades\DB;

final class JobBatchQueryService
{
    /**
     * @return array<int, array{
     *   id:string,
     *   name:string,
     *   total_jobs:int,
     *   pending_jobs:int,
     *   failed_jobs:int,
     *   created_at:int,
     *   finished_at:int|null,
     *   cancelled_at:int|null
     * }>
     */
    public function listRecent(?string $name = null, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $name = $name !== null ? trim($name) : null;
        if ($name === '') $name = null;

        $q = DB::table('job_batches')
            ->select([
                'id',
                'name',
                'total_jobs',
                'pending_jobs',
                'failed_jobs',
                'created_at',
                'finished_at',
                'cancelled_at',
            ])
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($name !== null) {
            $q->where('name', '=', $name);
        }

        /** @var array<int, object> $rows */
        $rows = $q->get()->all();

        return array_map(static function (object $r): array {
            return [
                'id' => (string) $r->id,
                'name' => (string) $r->name,
                'total_jobs' => (int) $r->total_jobs,
                'pending_jobs' => (int) $r->pending_jobs,
                'failed_jobs' => (int) $r->failed_jobs,
                'created_at' => (int) $r->created_at,
                'finished_at' => $r->finished_at !== null ? (int) $r->finished_at : null,
                'cancelled_at' => $r->cancelled_at !== null ? (int) $r->cancelled_at : null,
            ];
        }, $rows);
    }
}


