<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<Product> */
final class ProductPriceResearchResource extends JsonResource
{
    private function money2(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9\.\-]/', '', $trimmed) ?? '';
        if ($clean === '' || ! is_numeric($clean)) {
            return $trimmed;
        }

        return number_format((float) $clean, 2, '.', '');
    }

    private function addDecimals(string|int|float|null $a, string|int|float|null $b, int $scale = 6): ?string
    {
        $a = $a !== null ? trim((string) $a) : null;
        $b = $b !== null ? trim((string) $b) : null;
        if ($a === null || $a === '') {
            $a = '0';
        }
        if ($b === null || $b === '') {
            $b = '0';
        }

        if (! extension_loaded('bcmath')) {
            $out = (float) $a + (float) $b;
            return number_format($out, $scale, '.', '');
        }

        /** @var string $out */
        $out = bcadd($a, $b, $scale);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        /** @var CarbonImmutable|null $at */
        $at = $product->price_researched_at?->toImmutable();
        $ttlDays = max(1, (int) config('price_research.ttl_days', 14));
        $expired = $at === null || $at->lt(CarbonImmutable::now()->subDays($ttlDays));

        /** @var string|null $latestUnitCost */
        $latestUnitCost = $product->latest_unit_cost ?? null;
        /** @var string|null $latestShipping */
        $latestShipping = $product->latest_shipping_per_unit ?? null;
        /** @var string|null $latestSurcharge */
        $latestSurcharge = $product->latest_surcharge_per_unit ?? null;
        /** @var string|null $latestLanded */
        $latestLanded = $product->latest_landed_cost ?? null;

        $cost = $latestUnitCost;
        $landed = $latestLanded ?? ($cost !== null ? $this->addDecimals($cost, $this->addDecimals($latestShipping, $latestSurcharge, 6), 6) : null);

        return [
            'id' => $product->uuid,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'price_researched_at' => $product->price_researched_at?->toISOString(),
            'expired' => $expired,
            'vendor' => $product->vendor,
            'filled' => $product->filled_qty,
            'available' => $product->available_qty,
            'cost' => $this->money2($cost),
            'shipping_per_unit' => $this->money2($latestShipping),
            'landed_cost' => $this->money2($landed),
            'cost_low' => $this->money2($product->min_unit_cost ?? null),
            'cost_high' => $this->money2($product->max_unit_cost ?? null),
            'landed_cost_low' => $this->money2($product->min_landed_cost ?? null),
            'landed_cost_high' => $this->money2($product->max_landed_cost ?? null),
            'po_total_cost' => $this->money2($product->getAttribute('po_total_cost')),
            'selling_price' => $this->money2($product->sellingPrice?->selling_price),
            'quotes' => ProductPriceQuoteResource::collection($product->priceQuotes),
        ];
    }
}
