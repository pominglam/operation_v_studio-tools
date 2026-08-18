<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodInstockStatusService;
use Illuminate\Http\JsonResponse;

final class PlamodInstockSyncStatusController extends Controller
{
    public function __invoke(PlamodInstockStatusService $status): JsonResponse
    {
        return response()->json(['data' => $status->snapshot()]);
    }
}
