<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncPlamodAssetsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ProductInfoSyncController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $syncUuid = (string) Str::uuid();

        // Manual “Get product info” should attempt Plamod assets even if the product vendor is not Plamod.
        SyncPlamodAssetsJob::dispatch($syncUuid, $id, true);

        return response()->json([
            'ok' => true,
            'sync_uuid' => $syncUuid,
        ], 202);
    }
}


