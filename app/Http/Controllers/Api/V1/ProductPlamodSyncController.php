<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncPlamodAssetsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ProductPlamodSyncController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $syncUuid = (string) Str::uuid();

        SyncPlamodAssetsJob::dispatch($syncUuid, $id);

        return response()->json([
            'ok' => true,
            'sync_uuid' => $syncUuid,
        ], 202);
    }
}


