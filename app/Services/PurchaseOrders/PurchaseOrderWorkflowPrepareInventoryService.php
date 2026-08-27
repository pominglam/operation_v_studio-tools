<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPrepareInventoryException;
use App\Services\Shopify\Admin\Sync\ShopifyCatalogMirrorFreshnessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderWorkflowPrepareInventoryService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly ShopifyCatalogMirrorFreshnessService $mirrorFreshness,
        private readonly PurchaseOrderPoInventoryRefreshService $poInventoryRefresh,
    ) {}

    /**
     * @return array{
     *   sync_status: string,
     *   sync_mode: string,
     *   mirror_fresh: bool,
     *   max_age_seconds: int,
     *   products_last_completed_at: string|null,
     *   inventory_levels_last_completed_at: string|null,
     *   skus_refreshed?: int,
     *   inventory_items_refreshed?: int,
     *   lines_validated: int,
     *   shopify_quantities: array<int, array{sku:string, shopify_available:int|null}>
     * }
     */
    public function prepare(string $purchaseOrderUuid, bool $pullShopify = false): array
    {
        $freshness = $this->mirrorFreshness->snapshot();

        if ($pullShopify) {
            $refresh = $this->poInventoryRefresh->refreshForPurchaseOrder($purchaseOrderUuid);
            $summary = $this->validateAndSummarize($purchaseOrderUuid);

            return [
                ...$summary,
                'sync_mode' => 'po_inventory_refresh',
                'mirror_fresh' => $freshness['mirror_fresh'],
                'max_age_seconds' => $freshness['max_age_seconds'],
                'products_last_completed_at' => $freshness['products_last_completed_at'],
                'inventory_levels_last_completed_at' => $freshness['inventory_levels_last_completed_at'],
                'skus_refreshed' => $refresh['skus_refreshed'],
                'inventory_items_refreshed' => $refresh['inventory_items_refreshed'],
            ];
        }

        if ($freshness['mirror_fresh']) {
            $summary = $this->validateAndSummarize($purchaseOrderUuid);

            return [
                ...$summary,
                'sync_mode' => 'skipped_mirror_fresh',
                'mirror_fresh' => true,
                'max_age_seconds' => $freshness['max_age_seconds'],
                'products_last_completed_at' => $freshness['products_last_completed_at'],
                'inventory_levels_last_completed_at' => $freshness['inventory_levels_last_completed_at'],
            ];
        }

        $summary = $this->validateAndSummarize($purchaseOrderUuid);

        return [
            ...$summary,
            'sync_mode' => 'mirror_stale_confirmation_required',
            'mirror_fresh' => false,
            'max_age_seconds' => $freshness['max_age_seconds'],
            'products_last_completed_at' => $freshness['products_last_completed_at'],
            'inventory_levels_last_completed_at' => $freshness['inventory_levels_last_completed_at'],
        ];
    }

    /**
     * @return array{
     *   sync_status: string,
     *   lines_validated: int,
     *   shopify_quantities: array<int, array{sku:string, shopify_available:int|null}>
     * }
     */
    public function validateAndSummarize(string $purchaseOrderUuid): array
    {
        $this->validateReceivedQuantities($purchaseOrderUuid);

        $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        /** @var Collection<int, PurchaseOrderItem> $items */
        $items = $this->purchaseOrders->itemsForPurchaseOrderId((int) $po->id);

        $shopifyQuantities = [];
        foreach ($items as $item) {
            $sku = trim((string) $item->sku);
            if ($sku === '') {
                continue;
            }
            $shopifyQuantities[] = [
                'sku' => $sku,
                'shopify_available' => $this->shopifyAvailableForSku($sku),
            ];
        }

        return [
            'sync_status' => 'completed',
            'lines_validated' => count($items),
            'shopify_quantities' => $shopifyQuantities,
        ];
    }

    /**
     * @throws PurchaseOrderWorkflowPrepareInventoryException
     */
    public function validateReceivedQuantities(string $purchaseOrderUuid): void
    {
        $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        /** @var Collection<int, PurchaseOrderItem> $items */
        $items = $this->purchaseOrders->itemsForPurchaseOrderId((int) $po->id);

        $issues = [];
        foreach ($items as $item) {
            $sku = trim((string) $item->sku);
            $qtyReceived = $item->qty_received;
            if ($qtyReceived === null || (int) $qtyReceived <= 0) {
                $issues[] = [
                    'sku' => $sku !== '' ? $sku : '(unknown)',
                    'reason' => 'missing_or_zero_qty_received',
                ];
            }
            if ((int) ($item->qty_damaged ?? 0) > (int) ($qtyReceived ?? 0)) {
                $issues[] = [
                    'sku' => $sku !== '' ? $sku : '(unknown)',
                    'reason' => 'qty_damaged_exceeds_received',
                ];
            }
        }

        if ($issues !== []) {
            throw new PurchaseOrderWorkflowPrepareInventoryException(
                'Every PO line must have a received quantity before adding to available inventory.',
                $issues,
            );
        }
    }

    private function shopifyAvailableForSku(string $sku): ?int
    {
        /** @var object{inventory_quantity:?int, inventory_item_gid:?string}|null $variantRow */
        $variantRow = DB::table('shopify_product_variants')
            ->where('sku', '=', $sku)
            ->select(['inventory_quantity', 'inventory_item_gid'])
            ->first();

        if ($variantRow === null) {
            return null;
        }

        $itemGid = is_string($variantRow->inventory_item_gid ?? null) ? trim($variantRow->inventory_item_gid) : '';
        if ($itemGid !== '') {
            $levelsQuery = DB::table('shopify_inventory_levels')
                ->where('inventory_item_gid', '=', $itemGid)
                ->whereNotNull('quantity_available');

            if ($levelsQuery->exists()) {
                return max(0, (int) $levelsQuery->sum('quantity_available'));
            }
        }

        if ($variantRow->inventory_quantity !== null) {
            return (int) $variantRow->inventory_quantity;
        }

        return null;
    }
}
