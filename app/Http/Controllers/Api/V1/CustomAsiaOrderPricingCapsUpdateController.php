<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomAsiaOrderPricingCapsUpdateRequest;
use App\Services\CustomOrders\CustomAsiaOrderPricingCapsService;
use App\Support\CustomOrders\CustomAsiaOrderPricingCaps;
use Illuminate\Http\JsonResponse;

final class CustomAsiaOrderPricingCapsUpdateController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderPricingCapsService $caps,
    ) {}

    public function __invoke(CustomAsiaOrderPricingCapsUpdateRequest $request): JsonResponse
    {
        if ($request->boolean('reset')) {
            $this->caps->resetToDefaults();
        } else {
            $this->caps->upsert(CustomAsiaOrderPricingCaps::normalize([
                'merchandiser_commission_cap_cad' => $request->input('merchandiser_commission_cap_cad'),
                'opv_margin_cap_cad' => $request->input('opv_margin_cap_cad'),
            ]));
        }

        return response()->json([
            'data' => $this->caps->toArray(),
        ]);
    }
}
