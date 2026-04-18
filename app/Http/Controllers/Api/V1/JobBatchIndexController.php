<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Jobs\JobBatchQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JobBatchIndexController extends Controller
{
    public function __invoke(Request $request, JobBatchQueryService $service): JsonResponse
    {
        /** @var string|null $name */
        $name = $request->query('name');
        $limit = (int) ($request->query('limit') ?? 50);

        $rows = $service->listRecent(name: $name, limit: $limit);

        return response()->json([
            'ok' => true,
            'data' => $rows,
        ]);
    }
}
