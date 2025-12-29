<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Jobs\JobBatchItemQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

final class JobBatchItemsController extends Controller
{
    public function __invoke(string $id, Request $request, JobBatchItemQueryService $service): JsonResponse
    {
        $batch = Bus::findBatch($id);
        if ($batch === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $limit = (int) ($request->query('limit') ?? 25);

        return response()->json([
            'ok' => true,
            'data' => $service->getSummary(batchId: $id, limitPerSection: $limit),
        ]);
    }
}


