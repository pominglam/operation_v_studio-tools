<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Products\ProductSellingPriceRepository;
use App\DTOs\Products\ProductSellingPriceUpsertContext;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Support\Pricing\CharmPricingCalculator;
use App\Support\PurchaseOrders\ProductLatestArrivedLandedUnitCostResolver;

final class PurchaseOrderWorkflowSetPricesService
{
    private const string LANDED_COST_MULTIPLIER = '1.5';

    public function __construct(
        private readonly PurchaseOrderProductScopeService $scope,
        private readonly ProductSellingPriceRepository $sellingPrices,
        private readonly ProductLatestArrivedLandedUnitCostResolver $latestShippingCostedLanded,
    ) {}

    /**
     * @return array{
     *   multiplier: string,
     *   landed_cost_warning: string|null,
     *   new_prices: array<int, array<string, mixed>>,
     *   updates: array<int, array<string, mixed>>,
     *   unchanged: array<int, array<string, mixed>>,
     *   skipped_no_cost: array<int, array<string, mixed>>,
     *   apply_count: int
     * }
     */
    public function preview(string $purchaseOrderUuid): array
    {
        $plan = $this->buildPlan($purchaseOrderUuid);

        return [
            'multiplier' => self::LANDED_COST_MULTIPLIER,
            'landed_cost_warning' => $plan['landed_cost_warning'],
            'new_prices' => $plan['new_prices'],
            'updates' => $plan['updates'],
            'unchanged' => $plan['unchanged'],
            'skipped_no_cost' => $plan['skipped_no_cost'],
            'apply_count' => count($plan['new_prices']) + count($plan['updates']),
        ];
    }

    /**
     * @param  array<int, array{product_uuid: string, price: string}>  $priceOverrides
     * @return array{
     *   updated: int,
     *   skipped_no_cost: int,
     *   skipped_unchanged: int
     * }
     */
    public function apply(string $purchaseOrderUuid, array $priceOverrides = []): array
    {
        $po = $this->scope->findPoOrFail($purchaseOrderUuid);
        $plan = $this->buildPlan($purchaseOrderUuid);
        $overridesByProductUuid = $this->normalizeOverrides($priceOverrides);
        $updated = 0;
        $appliedOverrideUuids = [];
        $historyContext = new ProductSellingPriceUpsertContext('po_workflow', (int) $po->id);

        foreach ($this->rowsToApply($plan, $overridesByProductUuid) as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $productUuid = (string) ($row['product_uuid'] ?? '');
            $proposed = $overridesByProductUuid[$productUuid]
                ?? (is_string($row['proposed_price'] ?? null) ? trim($row['proposed_price']) : '');
            if ($productId <= 0 || $proposed === '') {
                continue;
            }

            /** @var Product|null $product */
            $product = Product::query()->find($productId);
            if ($product === null) {
                continue;
            }

            $this->sellingPrices->upsertForProduct($product, $proposed, 'CAD', $historyContext);
            $updated++;
            if (array_key_exists($productUuid, $overridesByProductUuid)) {
                $appliedOverrideUuids[$productUuid] = true;
            }
        }

        return [
            'updated' => $updated,
            'skipped_no_cost' => $this->countRowsWithoutOverrides($plan['skipped_no_cost'], $appliedOverrideUuids),
            'skipped_unchanged' => $this->countRowsWithoutOverrides($plan['unchanged'], $appliedOverrideUuids),
        ];
    }

    /**
     * @return array{
     *   landed_cost_warning: string|null,
     *   new_prices: array<int, array<string, mixed>>,
     *   updates: array<int, array<string, mixed>>,
     *   unchanged: array<int, array<string, mixed>>,
     *   skipped_no_cost: array<int, array<string, mixed>>
     * }
     */
    private function buildPlan(string $purchaseOrderUuid): array
    {
        $po = $this->scope->findPoOrFail($purchaseOrderUuid);

        $products = $this->scope->productsForPo($purchaseOrderUuid, false);
        $newProductIds = array_flip($this->scope->newProductIdsForPo($purchaseOrderUuid));

        /** @var array<int, int> $productIds */
        $productIds = $products
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $landedByProductId = $this->latestShippingCostedLanded->landedByProductId($productIds);

        $newPrices = [];
        $updates = [];
        $unchanged = [];
        $skippedNoCost = [];

        foreach ($products as $product) {
            $row = $this->rowForProduct(
                $product,
                isset($newProductIds[(int) $product->id]),
                $landedByProductId,
            );
            $category = (string) ($row['category'] ?? '');

            match ($category) {
                'new' => $newPrices[] = $row,
                'update' => $updates[] = $row,
                'unchanged' => $unchanged[] = $row,
                default => $skippedNoCost[] = $row,
            };
        }

        $this->sortPreviewRows($newPrices);
        $this->sortPreviewRows($updates);
        $this->sortPreviewRows($unchanged);
        $this->sortPreviewRows($skippedNoCost);

        return [
            'landed_cost_warning' => $this->landedCostWarning($po),
            'new_prices' => $newPrices,
            'updates' => $updates,
            'unchanged' => $unchanged,
            'skipped_no_cost' => $skippedNoCost,
        ];
    }

    private function landedCostWarning(PurchaseOrder $po): ?string
    {
        if ($po->shipping_total !== null) {
            return null;
        }

        return 'Shipping total has not been entered for this PO. Landed cost uses the latest PO with an entered shipping total; new products may remain unpriced until shipping is entered.';
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<int, string>  $landedByProductId
     */
    private function rowForProduct(Product $product, bool $isNewOnPo, array $landedByProductId): array
    {
        $landed = $this->landedCostForProduct($product, $landedByProductId);
        $current = $product->sellingPrice?->selling_price;
        $currentNormalized = $this->normalizeMoney($current);
        $proposed = CharmPricingCalculator::applyHighMultiplierReduction(
            CharmPricingCalculator::sellingPriceX99FromCost(
                $landed !== '' ? $landed : null,
                self::LANDED_COST_MULTIPLIER,
            ),
            $landed !== '' ? $landed : null,
        );
        $proposedNormalized = $this->normalizeMoney($proposed);

        $base = [
            'product_id' => (int) $product->id,
            'product_uuid' => (string) $product->uuid,
            'sku' => (string) $product->sku,
            'description' => (string) $product->description,
            'is_new_on_po' => $isNewOnPo,
            'landed_unit_cost' => $landed !== '' ? $landed : null,
            'current_price' => $currentNormalized,
            'current_multiplier' => $this->multiplierFromPriceAndCost($currentNormalized, $landed !== '' ? $landed : null),
            'proposed_price' => $proposedNormalized,
            'proposed_multiplier' => $this->multiplierFromPriceAndCost($proposedNormalized, $landed !== '' ? $landed : null),
        ];

        if ($proposedNormalized === null) {
            return [...$base, 'category' => 'skipped_no_cost'];
        }

        if ($currentNormalized === null) {
            return [...$base, 'category' => 'new'];
        }

        if ($currentNormalized === $proposedNormalized) {
            return [...$base, 'category' => 'unchanged', 'keep_reason' => null];
        }

        if ($this->moneyLessThan($proposedNormalized, $currentNormalized)) {
            return [...$base, 'category' => 'unchanged', 'keep_reason' => 'current_higher_than_formula'];
        }

        return [...$base, 'category' => 'update', 'keep_reason' => null];
    }

    /**
     * @param  array<int, string>  $landedByProductId
     */
    private function landedCostForProduct(Product $product, array $landedByProductId): string
    {
        $productId = (int) $product->id;
        if (isset($landedByProductId[$productId])) {
            return $landedByProductId[$productId];
        }

        // Do not fall back to products.latest_* or a PO without an entered shipping total.
        return '';
    }

    private function normalizeMoney(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $trimmed)) {
            return null;
        }

        return number_format((float) $trimmed, 2, '.', '');
    }

    /**
     * @param  array<int, array{product_uuid: string, price: string}>  $priceOverrides
     * @return array<string, string>
     */
    private function normalizeOverrides(array $priceOverrides): array
    {
        $normalized = [];

        foreach ($priceOverrides as $override) {
            $productUuid = trim($override['product_uuid']);
            $price = $this->normalizeMoney($override['price']);
            if ($productUuid === '' || $price === null) {
                continue;
            }

            $normalized[$productUuid] = $price;
        }

        return $normalized;
    }

    /**
     * @param  array{
     *   new_prices: array<int, array<string, mixed>>,
     *   updates: array<int, array<string, mixed>>,
     *   unchanged: array<int, array<string, mixed>>,
     *   skipped_no_cost: array<int, array<string, mixed>>
     * }  $plan
     * @param  array<string, string>  $overridesByProductUuid
     * @return array<int, array<string, mixed>>
     */
    private function rowsToApply(array $plan, array $overridesByProductUuid): array
    {
        $rows = [];

        foreach ([...$plan['new_prices'], ...$plan['updates'], ...$plan['unchanged'], ...$plan['skipped_no_cost']] as $row) {
            $productUuid = (string) ($row['product_uuid'] ?? '');
            $category = (string) ($row['category'] ?? '');
            if ($category === 'new' || $category === 'update' || array_key_exists($productUuid, $overridesByProductUuid)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, bool>  $appliedOverrideUuids
     */
    private function countRowsWithoutOverrides(array $rows, array $appliedOverrideUuids): int
    {
        return count(array_filter(
            $rows,
            static fn (array $row): bool => ! isset($appliedOverrideUuids[(string) ($row['product_uuid'] ?? '')]),
        ));
    }

    private function moneyLessThan(string $left, string $right): bool
    {
        return (float) $left < (float) $right;
    }

    private function multiplierFromPriceAndCost(?string $price, ?string $cost): ?string
    {
        if ($price === null || $cost === null || trim($cost) === '') {
            return null;
        }

        $costValue = (float) $cost;
        if ($costValue <= 0) {
            return null;
        }

        return number_format((float) $price / $costValue, 2, '.', '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sortPreviewRows(array &$rows): void
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $newCmp = ((int) ($b['is_new_on_po'] ?? false)) <=> ((int) ($a['is_new_on_po'] ?? false));
                if ($newCmp !== 0) {
                    return $newCmp;
                }

                return strcmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? ''));
            },
        );
    }
}
