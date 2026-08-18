<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodRestockCartStatusService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockCartRunStatusController extends Controller
{
    public function __invoke(PlamodRestockCartStatusService $status): JsonResponse
    {
        return response()->json(['data' => $status->snapshot()]);
    }
}
