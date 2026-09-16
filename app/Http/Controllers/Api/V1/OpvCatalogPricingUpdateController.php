<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OpvCatalogPricingUpdateRequest;
use App\Services\StorePreorders\OpvCatalogPricingSettingsService;
use App\Support\StorePreorders\OpvCatalogPricingSettings;
use Illuminate\Http\JsonResponse;

final class OpvCatalogPricingUpdateController extends Controller
{
    public function __construct(
        private readonly OpvCatalogPricingSettingsService $settings,
    ) {}

    public function __invoke(OpvCatalogPricingUpdateRequest $request): JsonResponse
    {
        if ($request->boolean('reset')) {
            $this->settings->resetToDefaults();
        } else {
            $this->settings->upsert(OpvCatalogPricingSettings::normalize([
                'price_multiplier' => $request->input('price_multiplier'),
                'default_deposit_percent' => $request->input('default_deposit_percent'),
            ]));
        }

        return response()->json([
            'data' => $this->settings->toArray(),
        ]);
    }
}
