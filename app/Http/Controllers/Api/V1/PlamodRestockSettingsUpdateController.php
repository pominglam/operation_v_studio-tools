<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodRestockSettingsUpdateRequest;
use App\Services\Plamod\PlamodRestockSettingsService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockSettingsUpdateController extends Controller
{
    public function __invoke(
        PlamodRestockSettingsUpdateRequest $request,
        PlamodRestockSettingsService $settings,
    ): JsonResponse {
        $shippingPercent = (float) $request->validated('shipping_percent');
        $excludedSeries = $request->validated('excluded_series');
        $excludedProductTerms = $request->validated('excluded_product_terms');

        return response()->json([
            'data' => $settings->save(
                $shippingPercent,
                is_array($excludedSeries) ? $excludedSeries : null,
                is_array($excludedProductTerms) ? $excludedProductTerms : null,
            ),
        ]);
    }
}
