<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPrepareInventoryException;
use App\Services\Shopify\Admin\Sync\ShopifyInventoryLevelSyncRunner;
use App\Services\Shopify\Admin\Sync\ShopifySyncMetrics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderPoInventoryRefreshService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly ShopifyAdminGraphQlClientInterface $shopifyClient,
        private readonly ShopifyInventoryLevelSyncRunner $inventoryLevels,
    ) {}

    /**
     * @return array{skus_refreshed: int, inventory_items_refreshed: int}
     */
    public function refreshForPurchaseOrder(string $purchaseOrderUuid): array
    {
        $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        /** @var Collection<int, PurchaseOrderItem> $items */
        $items = $this->purchaseOrders->itemsForPurchaseOrderId((int) $po->id);

        $skus = [];
        foreach ($items as $item) {
            $sku = trim((string) $item->sku);
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
        $skus = array_values(array_unique($skus));

        if ($skus === []) {
            return ['skus_refreshed' => 0, 'inventory_items_refreshed' => 0];
        }

        $missingSkus = [];
        $inventoryItemGids = [];
        foreach ($skus as $sku) {
            /** @var object{inventory_item_gid:?string}|null $variant */
            $variant = DB::table('shopify_product_variants')
                ->where('sku', '=', $sku)
                ->select('inventory_item_gid')
                ->first();

            $gid = is_string($variant?->inventory_item_gid ?? null) ? trim($variant->inventory_item_gid) : '';
            if ($gid === '') {
                $missingSkus[] = $sku;

                continue;
            }
            $inventoryItemGids[$gid] = $gid;
        }

        if ($missingSkus !== []) {
            $issues = array_map(
                static fn (string $sku): array => [
                    'sku' => $sku,
                    'reason' => 'missing_shopify_mirror_variant',
                ],
                $missingSkus,
            );
            throw new PurchaseOrderWorkflowPrepareInventoryException(
                'Some PO SKUs are missing from the Shopify mirror. Run Maintenance (full product + inventory sync) or `php artisan shopify:sync products` then retry Prepare.',
                $issues,
            );
        }

        $gids = array_values($inventoryItemGids);
        $metrics = new ShopifySyncMetrics;
        $this->inventoryLevels->syncInventoryItemGids($this->shopifyClient, $gids, $metrics);

        return [
            'skus_refreshed' => count($skus),
            'inventory_items_refreshed' => count($gids),
        ];
    }
}
