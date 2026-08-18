<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodRestockCartRecheckService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockCartRunRecheckController extends Controller
{
    public function __invoke(PlamodRestockCartRecheckService $recheck): JsonResponse
    {
        $result = $recheck->recheck();
        if (($result['ok'] ?? false) !== true) {
            return response()->json(['data' => $result], 422);
        }

        return response()->json(['data' => $result]);
    }
}
