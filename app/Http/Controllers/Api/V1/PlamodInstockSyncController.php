<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodInstockDispatchService;
use Illuminate\Http\JsonResponse;

final class PlamodInstockSyncController extends Controller
{
    public function __invoke(PlamodInstockDispatchService $dispatch): JsonResponse
    {
        $result = $dispatch->dispatch();
        if (($result['ok'] ?? false) !== true) {
            return response()->json(['data' => $result], 422);
        }

        return response()->json(['data' => $result]);
    }
}
