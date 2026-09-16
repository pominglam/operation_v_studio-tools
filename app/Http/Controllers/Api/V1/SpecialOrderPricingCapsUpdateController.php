<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpecialOrderPricingCapsUpdateRequest;
use App\Services\SpecialOrders\SpecialOrderPricingCapsService;
use App\Support\SpecialOrders\SpecialOrderPricingCaps;
use Illuminate\Http\JsonResponse;

final class SpecialOrderPricingCapsUpdateController extends Controller
{
    public function __construct(
        private readonly SpecialOrderPricingCapsService $caps,
    ) {}

    public function __invoke(SpecialOrderPricingCapsUpdateRequest $request): JsonResponse
    {
        if ($request->boolean('reset')) {
            $this->caps->resetToDefaults();
        } else {
            $this->caps->upsert(SpecialOrderPricingCaps::normalize([
                'merchandiser_commission_cap_cad' => $request->input('merchandiser_commission_cap_cad'),
                'opv_margin_cap_cad' => $request->input('opv_margin_cap_cad'),
                'default_shipping_cost_amount' => $request->input('default_shipping_cost_amount'),
                'default_shipping_cost_currency' => $request->input('default_shipping_cost_currency'),
                'default_shipping_cost_per_kg_cny' => $request->input('default_shipping_cost_per_kg_cny'),
            ]));
        }

        return response()->json([
            'data' => $this->caps->toArray(),
        ]);
    }
}
