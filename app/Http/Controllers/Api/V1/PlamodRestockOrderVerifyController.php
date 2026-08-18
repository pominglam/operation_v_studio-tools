<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodRestockOrderVerifyService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockOrderVerifyController extends Controller
{
    public function __invoke(PlamodRestockOrderVerifyService $verify): JsonResponse
    {
        $result = $verify->verify();
        if (($result['ok'] ?? false) !== true) {
            return response()->json(['data' => $result], 422);
        }

        return response()->json(['data' => $result]);
    }
}
