<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductSellingPriceUpsertContext;
use App\Models\PlamodPreorder;
use App\Models\Product;
use App\Services\Products\Exceptions\DuplicateSkuException;
use App\Services\Products\ProductCreateService;
use App\Services\Products\ProductSellingPriceService;
use App\Support\Pricing\OpvStandardCatalogPrice;
use App\Support\StorePreorders\OpvCatalogPricingSettings;

final class StorePreorderProductEnsureService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCreateService $create,
        private readonly ProductSellingPriceService $sellingPrices,
        private readonly StorePreorderSuggestedSellService $suggestedSell,
    ) {}

    /**
     * @return array{product: Product, created: bool}
     */
    public function ensureFromPlamod(PlamodPreorder $plamod, ?string $sellingOverride = null): array
    {
        $sku = trim((string) $plamod->sku);
        $existing = $this->products->findBySkus([$sku])->first();
        if ($existing instanceof Product) {
            $this->applyCostAndPrice($existing, $plamod, false, $sellingOverride);

            return ['product' => $existing, 'created' => false];
        }

        try {
            $product = $this->create->create([
                'sku' => $sku,
                'barcode' => $this->nullableTrim($plamod->barcode),
                'description' => $this->catalogDescription($plamod, $sku),
                'manufacturer' => $this->nullableTrim($plamod->manufacturer),
                'series' => $this->nullableTrim($plamod->series),
                'vendor' => 'Plamod',
                'available' => 0,
            ]);
        } catch (DuplicateSkuException) {
            $raced = $this->products->findBySkus([$sku])->first();
            if ($raced instanceof Product) {
                $this->applyCostAndPrice($raced, $plamod, false, $sellingOverride);

                return ['product' => $raced, 'created' => false];
            }

            throw new DuplicateSkuException('SKU already exists.');
        }

        $this->applyCostAndPrice($product, $plamod, true, $sellingOverride);

        return ['product' => $product, 'created' => true];
    }

    public function sellingPriceFromPlamod(PlamodPreorder $plamod, ?string $override = null): ?string
    {
        $normalized = OpvCatalogPricingSettings::normalizeMoney($override);
        if ($normalized !== null) {
            return OpvStandardCatalogPrice::fromEnteredPrice($normalized);
        }

        return $this->suggestedSell->fromPoCost($this->costBasis($plamod));
    }

    public function costBasis(PlamodPreorder $plamod): ?string
    {
        foreach ([$plamod->price_preorder, $plamod->price_stock] as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function applyCostAndPrice(
        Product $product,
        PlamodPreorder $plamod,
        bool $forceSelling,
        ?string $sellingOverride,
    ): void {
        $cost = $this->costBasis($plamod);
        if ($cost !== null && ($product->latest_unit_cost === null || trim((string) $product->latest_unit_cost) === '')) {
            $product->latest_unit_cost = $cost;
            $this->products->save($product);
        }

        $selling = $this->sellingPriceFromPlamod($plamod, $sellingOverride);
        if ($selling === null) {
            return;
        }

        $product->loadMissing('sellingPrice');
        $hasSelling = $product->sellingPrice !== null && $product->sellingPrice->selling_price !== null;
        $hasOverride = OpvCatalogPricingSettings::normalizeMoney($sellingOverride) !== null;
        if (! $forceSelling && ! $hasOverride && $hasSelling) {
            return;
        }

        $this->sellingPrices->upsertForProductUuid(
            $product->uuid,
            $selling,
            'CAD',
            new ProductSellingPriceUpsertContext('store_preorder'),
        );
    }

    private function catalogDescription(PlamodPreorder $plamod, string $sku): string
    {
        $name = trim((string) $plamod->product_name);
        if ($name !== '' && strcasecmp($name, $sku) !== 0) {
            return $name;
        }

        $series = $this->nullableTrim($plamod->series);
        if ($series !== null) {
            return $series;
        }

        return $sku;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
