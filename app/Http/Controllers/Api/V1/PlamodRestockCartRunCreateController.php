<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodRestockCartRunCreateRequest;
use App\Services\Plamod\PlamodRestockCartDispatchService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockCartRunCreateController extends Controller
{
    public function __invoke(PlamodRestockCartRunCreateRequest $request, PlamodRestockCartDispatchService $dispatch): JsonResponse
    {
        $result = $dispatch->dispatch($request->skus());
        if (($result['ok'] ?? false) !== true) {
            return response()->json(['data' => $result], 422);
        }

        return response()->json(['data' => $result]);
    }
}
