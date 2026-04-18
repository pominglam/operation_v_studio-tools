<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductMissingInfoSyncRequest;
use App\Services\Products\ProductMissingInfoSyncService;
use Illuminate\Http\JsonResponse;

final class ProductMissingInfoSyncController extends Controller
{
    public function __invoke(ProductMissingInfoSyncRequest $request, ProductMissingInfoSyncService $service): JsonResponse
    {
        /** @var string|null $search */
        $search = $request->validated('search');
        /** @var array<int, string> $types */
        $types = $request->validated('types') ?? [];
        /** @var array<int, string> $vendors */
        $vendors = $request->validated('vendors') ?? [];
        /** @var array<int, string> $missing */
        $missing = $request->validated('missing') ?? [];
        $dryRun = (bool) ($request->validated('dry_run') ?? false);

        $result = $service->syncMissingInfo($search, $types, $vendors, $missing, $dryRun);

        return response()->json([
            'ok' => true,
            'queued' => $result->queued,
            'dry_run' => $result->dryRun,
            'batch_id' => $result->batchId,
        ]);
    }
}
