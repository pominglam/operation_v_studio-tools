<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\Services\PriceResearch\FxRateService;
use App\Support\CustomOrders\CustomAsiaOrderCurrency;
use Illuminate\Support\Carbon;

final class CustomAsiaOrderLandedCostService
{
    public function __construct(
        private readonly FxRateService $fxRates,
    ) {}

    /**
     * @return array{
     *   landed_cost_cad: string|null,
     *   product_fx_rate_to_cad: string|null,
     *   shipping_fx_rate_to_cad: string|null,
     *   fx_rate_date: Carbon|null
     * }
     */
    public function compute(
        ?string $productAmount,
        ?string $productCurrency,
        ?string $shippingAmount,
        ?string $shippingCurrency,
    ): array {
        if ($productAmount === null || $productCurrency === null || $shippingAmount === null || $shippingCurrency === null) {
            return [
                'landed_cost_cad' => null,
                'product_fx_rate_to_cad' => null,
                'shipping_fx_rate_to_cad' => null,
                'fx_rate_date' => null,
            ];
        }

        $productCurrency = CustomAsiaOrderCurrency::frankfurterCode($productCurrency);
        $shippingCurrency = CustomAsiaOrderCurrency::frankfurterCode($shippingCurrency);

        $productFx = $this->fxRates->rate($productCurrency, CustomAsiaOrderCurrency::CAD);
        $shippingFx = $this->fxRates->rate($shippingCurrency, CustomAsiaOrderCurrency::CAD);

        $productCad = round((float) $productAmount * $productFx, 2);
        $shippingCad = round((float) $shippingAmount * $shippingFx, 2);
        $landed = round($productCad + $shippingCad, 2);

        return [
            'landed_cost_cad' => number_format($landed, 2, '.', ''),
            'product_fx_rate_to_cad' => number_format($productFx, 6, '.', ''),
            'shipping_fx_rate_to_cad' => number_format($shippingFx, 6, '.', ''),
            'fx_rate_date' => Carbon::today(),
        ];
    }
}
