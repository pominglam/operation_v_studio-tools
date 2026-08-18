<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Enums\PlamodRestockSkuDecisionStatus;
use App\Models\PlamodInstockItem;
use App\Models\PlamodInstockSyncLog;
use App\Models\PlamodRestockSkuDecision;
use App\Models\Product;
use App\Services\Products\ProductInboundOpenPoQtySql;
use App\Support\Plamod\PlamodRestockCostCalculator;
use App\Support\Plamod\PlamodRestockTotalsCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PlamodRestockProposalService
{
    public function __construct(
        private readonly PlamodRestockSettingsService $settings,
        private readonly PlamodRestockPlannedMaintainService $plannedMaintain,
        private readonly PlamodRestockReorderOverrideService $reorderOverrides,
        private readonly PlamodPreorderOfferResolver $preorderOffers,
    ) {}

    /**
     * @return array{
     *   snapshot: array{sync_log_id: int|null, synced_at: string|null, item_count: int},
     *   shipping_percent: float,
     *   exclusions: array{excluded_series: array<int, string>, excluded_product_terms: array<int, string>},
     *   existing: array<int, array<string, mixed>>,
     *   new_products: array<int, array<string, mixed>>,
     *   totals: array{
     *     unique_products: int,
     *     units: int,
     *     product: string,
     *     shipping: string,
     *     landed: string,
     *     lines_with_missing_price: int,
     *     existing: array{unique_products: int, units: int, product: string, shipping: string, landed: string, lines_with_missing_price: int},
     *     new_products: array{unique_products: int, units: int, product: string, shipping: string, landed: string, lines_with_missing_price: int}
     *   },
     *   meta: array<string, int>
     * }
     */
    public function build(bool $hideDismissed = true, bool $onlyIncludedNew = false): array
    {
        $settings = $this->settings->get();
        $shippingPercent = $settings['shipping_percent'];
        $instockBySku = PlamodInstockItem::query()->get()->keyBy('sku');
        $decisionsBySku = PlamodRestockSkuDecision::query()->get()->keyBy('sku');
        $reorderOverrideBySku = $this->reorderOverrides->bySku();
        $plannedMaintainBySku = $this->plannedMaintain->pendingBySku();
        $latestLog = PlamodInstockSyncLog::query()
            ->where('status', '=', 'completed')
            ->orderByDesc('finished_at')
            ->first();

        $inboundExpr = ProductInboundOpenPoQtySql::expression(false);
        $reorderExpr = "case when (coalesce(products.maintain_qty, 0) - coalesce(products.available_qty, 0) - ({$inboundExpr})) > 0"
            ." then (coalesce(products.maintain_qty, 0) - coalesce(products.available_qty, 0) - ({$inboundExpr})) else 0 end";

        /** @var Collection<int, object> $productRows */
        $productRows = Product::query()
            ->whereNull('archived_at')
            ->whereIn('sku', $instockBySku->keys()->all())
            ->select([
                'products.id',
                'products.uuid',
                'products.sku',
                'products.description',
                'products.barcode',
                'products.type',
                DB::raw('coalesce(products.available_qty, 0) as available_qty'),
                DB::raw('coalesce(products.maintain_qty, 0) as maintain_qty'),
                DB::raw("{$inboundExpr} as not_arrived_qty"),
                DB::raw("{$reorderExpr} as reorder_qty"),
                'products.latest_unit_cost',
                'products.latest_landed_unit_cost',
            ])
            ->get();

        $existingSkus = [];
        $existingProductSkus = [];
        foreach ($productRows as $row) {
            $sku = (string) $row->sku;
            /** @var PlamodInstockItem|null $instock */
            $instock = $instockBySku->get($sku);
            if ($instock === null) {
                continue;
            }

            $existingProductSkus[] = $sku;
        }

        $preorderBySku = $this->preorderOffers->bySkus($existingProductSkus);

        $existing = [];
        foreach ($productRows as $row) {
            $sku = (string) $row->sku;
            $overrideQty = $reorderOverrideBySku[$sku] ?? null;

            /** @var PlamodInstockItem|null $instock */
            $instock = $instockBySku->get($sku);
            if ($instock === null) {
                continue;
            }

            $existingSkus[$sku] = true;
            $existing[] = $this->mapExistingRow(
                $row,
                $instock,
                $shippingPercent,
                $preorderBySku[$sku] ?? ['committed_qty' => 0, 'shipments' => []],
                $overrideQty,
            );
        }

        $newProducts = [];
        $dismissedCount = 0;
        $undecidedCount = 0;
        $includedCount = 0;
        $laterCount = 0;
        $newMissingPriceCount = 0;

        foreach ($instockBySku as $sku => $instock) {
            if (isset($existingSkus[$sku])) {
                continue;
            }

            if ($instock->price_stock === null) {
                $newMissingPriceCount++;
            }

            /** @var PlamodRestockSkuDecision|null $decision */
            $decision = $decisionsBySku->get($sku);
            $status = $decision?->status?->value ?? 'undecided';
            if ($decision === null && $this->settings->matchesExclusion(
                $instock->product_name,
                $instock->series,
                $settings,
            )) {
                $status = PlamodRestockSkuDecisionStatus::Dismissed->value;
            }

            if ($status === PlamodRestockSkuDecisionStatus::Dismissed->value) {
                $dismissedCount++;
                if ($hideDismissed) {
                    continue;
                }
            } elseif ($status === PlamodRestockSkuDecisionStatus::Included->value) {
                $includedCount++;
            } elseif ($status === PlamodRestockSkuDecisionStatus::Later->value) {
                $laterCount++;
                if ($onlyIncludedNew) {
                    continue;
                }
            } elseif ($status === 'undecided') {
                $undecidedCount++;
                if ($onlyIncludedNew) {
                    continue;
                }
            }

            if ($onlyIncludedNew && $status !== PlamodRestockSkuDecisionStatus::Included->value) {
                continue;
            }

            $newProducts[] = $this->mapNewRow(
                $instock,
                $decision,
                $shippingPercent,
                $status,
                $plannedMaintainBySku[$instock->sku] ?? null,
            );
        }

        usort($newProducts, static function (array $a, array $b): int {
            $aDate = $a['release_date'] ?? '';
            $bDate = $b['release_date'] ?? '';
            if ($aDate === $bDate) {
                return strcmp((string) $a['sku'], (string) $b['sku']);
            }
            if ($aDate === '') {
                return 1;
            }
            if ($bDate === '') {
                return -1;
            }

            return strcmp((string) $bDate, (string) $aDate);
        });

        return [
            'snapshot' => [
                'sync_log_id' => $latestLog !== null ? (int) $latestLog->id : null,
                'synced_at' => $latestLog?->finished_at?->toISOString(),
                'item_count' => $instockBySku->count(),
            ],
            'shipping_percent' => $shippingPercent,
            'exclusions' => [
                'excluded_series' => $settings['excluded_series'],
                'excluded_product_terms' => $settings['excluded_product_terms'],
            ],
            'existing' => $existing,
            'new_products' => $newProducts,
            'totals' => PlamodRestockTotalsCalculator::compute($existing, $newProducts, $shippingPercent),
            'meta' => [
                'existing_count' => count($existing),
                'new_count' => count($newProducts),
                'dismissed_count' => $dismissedCount,
                'undecided_new_count' => $undecidedCount,
                'included_new_count' => $includedCount,
                'later_new_count' => $laterCount,
                'new_missing_price_count' => $newMissingPriceCount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array{committed_qty: int, shipments: array<int, array{offer_id: string|null, quantity: int, eta_date: string|null, eta_label: string|null, po_due_date: string|null}>}  $preorder
     */
    private function mapExistingRow(
        object $row,
        PlamodInstockItem $instock,
        float $shippingPercent,
        array $preorder,
        ?int $overrideQty = null,
    ): array {
        $reorderQty = max(0, (int) $row->reorder_qty);
        $proposedQty = $overrideQty ?? $reorderQty;
        $lastCosts = PlamodRestockCostCalculator::lastLandedBreakdown(
            $row->latest_unit_cost !== null ? (string) $row->latest_unit_cost : null,
            $row->latest_landed_unit_cost !== null ? (string) $row->latest_landed_unit_cost : null,
        );
        $newCosts = PlamodRestockCostCalculator::newLandedBreakdown(
            $instock->price_stock !== null ? (string) $instock->price_stock : null,
            $shippingPercent,
        );
        $lineTotal = self::lineTotal($proposedQty, $newCosts);

        return [
            'product_uuid' => (string) $row->uuid,
            'sku' => (string) $row->sku,
            'product_name' => (string) $row->description,
            'barcode' => is_string($row->barcode) ? $row->barcode : null,
            'type' => is_string($row->type) && trim($row->type) !== '' ? trim($row->type) : null,
            'release_date' => $instock->release_date?->toDateString(),
            'release_date_label' => $instock->release_date_label,
            'is_recent_release' => $this->isRecentRelease($instock->release_date?->toDateString()),
            'available_qty' => (int) $row->available_qty,
            'maintain_qty' => (int) $row->maintain_qty,
            'not_arrived_qty' => (int) $row->not_arrived_qty,
            'preorder_committed_qty' => max(0, (int) ($preorder['committed_qty'] ?? 0)),
            'preorder_shipments' => is_array($preorder['shipments'] ?? null) ? $preorder['shipments'] : [],
            'reorder_qty' => $reorderQty,
            'reorder_qty_override' => $overrideQty,
            'is_reorder_overridden' => $overrideQty !== null,
            'proposed_qty' => $proposedQty,
            'last_landed_cost' => $lastCosts,
            'new_landed_cost' => $newCosts,
            'line_total' => $lineTotal,
            'cost_delta_high' => PlamodRestockCostCalculator::isProductCostDeltaAboveThreshold(
                $lastCosts['product'] ?? null,
                $newCosts['product'] ?? null,
            ),
            'cost_delta_percent' => PlamodRestockCostCalculator::productCostDeltaPercent(
                $lastCosts['product'] ?? null,
                $newCosts['product'] ?? null,
            ),
            'plamod_pdp_url' => $instock->plamod_pdp_url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapNewRow(
        PlamodInstockItem $instock,
        ?PlamodRestockSkuDecision $decision,
        float $shippingPercent,
        string $status,
        ?int $plannedMaintainQty = null,
    ): array {
        $newCosts = PlamodRestockCostCalculator::newLandedBreakdown(
            $instock->price_stock !== null ? (string) $instock->price_stock : null,
            $shippingPercent,
        );

        $orderQty = $decision?->order_qty;

        return [
            'sku' => $instock->sku,
            'product_name' => $instock->product_name,
            'barcode' => $instock->barcode,
            'series' => $instock->series,
            'category' => $instock->category,
            'release_date' => $instock->release_date?->toDateString(),
            'release_date_label' => $instock->release_date_label,
            'is_recent_release' => $this->isRecentRelease($instock->release_date?->toDateString()),
            'status' => $status,
            'order_qty' => $orderQty,
            'planned_maintain_qty' => $plannedMaintainQty,
            'last_landed_cost' => null,
            'new_landed_cost' => $newCosts,
            'line_total' => self::lineTotal($orderQty !== null ? (int) $orderQty : 0, $newCosts),
            'cost_delta_high' => false,
            'cost_delta_percent' => null,
            'price_missing' => $instock->price_stock === null,
            'image_url' => $instock->source_image_url,
            'plamod_pdp_url' => $instock->plamod_pdp_url,
        ];
    }

    /**
     * @param  array{product: string, shipping: string, landed: string}|null  $cost
     * @return array{product: string, shipping: string, landed: string}|null
     */
    private static function lineTotal(int $qty, ?array $cost): ?array
    {
        if ($qty <= 0 || $cost === null) {
            return null;
        }

        $product = number_format($qty * (float) $cost['product'], 2, '.', '');

        return [
            'product' => $product,
            'shipping' => '0.00',
            'landed' => $product,
        ];
    }

    private function isRecentRelease(?string $releaseDate): bool
    {
        if ($releaseDate === null || $releaseDate === '') {
            return false;
        }

        try {
            return now()->subMonths(6)->toDateString() <= $releaseDate;
        } catch (\Throwable) {
            return false;
        }
    }
}
