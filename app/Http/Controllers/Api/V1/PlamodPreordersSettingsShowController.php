<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodPreorderSettingsService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersSettingsShowController extends Controller
{
    public function __invoke(PlamodPreorderSettingsService $settings): JsonResponse
    {
        return response()->json(['data' => $settings->get()]);
    }
}
