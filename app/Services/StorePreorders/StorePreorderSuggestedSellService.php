<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\Services\Plamod\PlamodRestockSettingsService;
use App\Support\Plamod\PlamodRestockCostCalculator;
use App\Support\Pricing\OpvStandardCatalogPrice;

/**
 * Catalog sell $ for store preorders: estimated landed (PO cost + restock shipping %)
 * × OPV margin, then closest X.99 (ties go up).
 */
final class StorePreorderSuggestedSellService
{
    private ?float $shippingPercent = null;

    public function __construct(
        private readonly PlamodRestockSettingsService $restock,
        private readonly OpvCatalogPricingSettingsService $pricing,
    ) {}

    public function fromPoCost(?string $poCost): ?string
    {
        $landed = $this->estimatedLanded($poCost);
        if ($landed === null) {
            return null;
        }

        return OpvStandardCatalogPrice::fromCost($landed, $this->pricing->multiplier());
    }

    public function estimatedLanded(?string $poCost): ?string
    {
        $breakdown = PlamodRestockCostCalculator::newLandedBreakdown($poCost, $this->shippingPercent());
        if ($breakdown === null) {
            return null;
        }

        return $breakdown['landed'];
    }

    public function shippingPercent(): float
    {
        return $this->shippingPercent ??= $this->restock->get()['shipping_percent'];
    }
}
