<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPrepareInventoryException;
use App\Services\Shopify\Admin\Sync\ShopifyErpSyncCoordinator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderWorkflowPrepareInventoryService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly ShopifyErpSyncCoordinator $shopifySync,
    ) {}

    /**
     * @return array{
     *   sync_status: string,
     *   lines_validated: int,
     *   shopify_quantities: array<int, array{sku:string, shopify_available:int|null}>
     * }
     */
    public function prepare(string $purchaseOrderUuid): array
    {
        $productsSync = $this->shopifySync->sync('products');
        if ($productsSync->status !== 'completed') {
            throw new PurchaseOrderWorkflowPrepareInventoryException(
                'Shopify product sync failed: '.(string) ($productsSync->error_summary ?? 'unknown error'),
            );
        }

        $inventorySync = $this->shopifySync->sync('inventory_levels');
        if ($inventorySync->status !== 'completed') {
            throw new PurchaseOrderWorkflowPrepareInventoryException(
                'Shopify inventory sync failed: '.(string) ($inventorySync->error_summary ?? 'unknown error'),
            );
        }

        return $this->validateAndSummarize($purchaseOrderUuid);
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

        if ($variantRow->inventory_quantity !== null) {
            return (int) $variantRow->inventory_quantity;
        }

        $itemGid = is_string($variantRow->inventory_item_gid ?? null) ? $variantRow->inventory_item_gid : null;
        if ($itemGid === null || $itemGid === '') {
            return null;
        }

        /** @var object{quantity_available:?int}|null $level */
        $level = DB::table('shopify_inventory_levels')
            ->where('inventory_item_gid', '=', $itemGid)
            ->select('quantity_available')
            ->orderByDesc('id')
            ->first();

        if ($level === null || $level->quantity_available === null) {
            return null;
        }

        return (int) $level->quantity_available;
    }
}
