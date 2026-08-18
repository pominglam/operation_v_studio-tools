<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodRestockSettingsService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockSettingsShowController extends Controller
{
    public function __invoke(PlamodRestockSettingsService $settings): JsonResponse
    {
        return response()->json(['data' => $settings->get()]);
    }
}
