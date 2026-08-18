<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodRestockOrderVerifyService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockOrderVerifyShowController extends Controller
{
    public function __invoke(PlamodRestockOrderVerifyService $verify): JsonResponse
    {
        return response()->json(['data' => $verify->snapshot()]);
    }
}
