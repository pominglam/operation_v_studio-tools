<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StorePreorders\OpvCatalogPricingSettingsService;
use Illuminate\Http\JsonResponse;

final class OpvCatalogPricingShowController extends Controller
{
    public function __construct(
        private readonly OpvCatalogPricingSettingsService $settings,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->toArray(),
        ]);
    }
}
