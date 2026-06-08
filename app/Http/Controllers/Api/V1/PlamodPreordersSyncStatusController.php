<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodPreorderStatusService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersSyncStatusController extends Controller
{
    public function __invoke(PlamodPreorderStatusService $status): JsonResponse
    {
        return response()->json(['data' => $status->snapshot()]);
    }
}
