<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodInstockSyncRetryRequest;
use App\Services\Plamod\PlamodInstockDispatchService;
use Illuminate\Http\JsonResponse;

final class PlamodInstockSyncRetryController extends Controller
{
    public function __invoke(
        PlamodInstockSyncRetryRequest $request,
        PlamodInstockDispatchService $dispatch,
    ): JsonResponse {
        $result = $dispatch->dispatchRetry($request->filters());
        if (($result['ok'] ?? false) !== true) {
            return response()->json(['data' => $result], 422);
        }

        return response()->json(['data' => $result]);
    }
}
