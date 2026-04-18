<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;

final class JobBatchShowController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $batch = Bus::findBatch($id);
        if ($batch === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $total = (int) $batch->totalJobs;
        $pending = (int) $batch->pendingJobs;
        $failed = (int) $batch->failedJobs;
        $processed = max(0, $total - $pending);
        $percent = $total > 0 ? (int) round(($processed / $total) * 100) : 100;

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $batch->id,
                'name' => $batch->name,
                'total_jobs' => $total,
                'pending_jobs' => $pending,
                'processed_jobs' => $processed,
                'failed_jobs' => $failed,
                'progress_percent' => $percent,
                'cancelled' => (bool) $batch->cancelledAt,
                'finished_at' => $batch->finishedAt?->toISOString(),
                'cancelled_at' => $batch->cancelledAt?->toISOString(),
            ],
        ]);
    }
}
