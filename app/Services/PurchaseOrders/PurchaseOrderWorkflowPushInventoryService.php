<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Products\ProductRepository;
use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Models\Product;
use App\Services\Products\LatestArrivalPushProductSortService;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPushInventoryException;
use App\Services\Shopify\Admin\Write\ShopifyInventoryLocationResolver;
use App\Services\Shopify\Admin\Write\ShopifyLatestArrivalsCollectionReorderService;
use App\Services\Shopify\Admin\Write\ShopifyProductMirrorBySkuResolver;
use App\Services\Shopify\Admin\Write\ShopifyProductUpsertFromErpService;
use App\Services\Shopify\Admin\Write\ShopifyWriteScopeGuard;
use App\Services\Shopify\CloudflaredTunnel;
use App\Support\Products\ProductHoldQty;

final class PurchaseOrderWorkflowPushInventoryService
{
    public function __construct(
        private readonly PurchaseOrderProductScopeService $scope,
        private readonly ProductRepository $products,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly ShopifyInventoryLocationResolver $locationResolver,
        private readonly ShopifyProductMirrorBySkuResolver $mirrorBySku,
        private readonly ShopifyProductUpsertFromErpService $shopifyUpsert,
        private readonly CloudflaredTunnel $tunnel,
        private readonly LatestArrivalPushProductSortService $productSort,
        private readonly ShopifyLatestArrivalsCollectionReorderService $collectionReorder,
    ) {}

    /**
     * @return array{
     *   location_gid: string,
     *   location_name: string|null,
     *   write_products_scope_ok: bool,
     *   write_inventory_scope_ok: bool,
     *   write_publications_scope_ok: bool,
     *   images_enabled: bool,
     *   tunnel_url: string|null,
     *   products: array<int, array<string, mixed>>,
     *   push_count: int,
     *   product_uuids: array<int, string>
     * }
     */
    public function preview(string $purchaseOrderUuid): array
    {
        $locationGid = $this->locationResolver->resolveLocationGid();
        $tunnelStatus = $this->tunnel->status();
        $tunnelUrl = is_string($tunnelStatus['tunnel_url'] ?? null) ? trim($tunnelStatus['tunnel_url']) : '';
        $imagesEnabled = ($tunnelStatus['running'] ?? false) === true && $tunnelUrl !== '';

        $rows = [];

        foreach ($this->scope->productsForPo($purchaseOrderUuid, false) as $product) {
            $rows[] = $this->buildProductRow($product, $locationGid);
        }

        $rows = $this->productSort->sortPreviewRows($rows);

        $pushUuids = [];
        foreach ($rows as $row) {
            if (($row['push_eligible'] ?? false) === true) {
                $pushUuids[] = (string) $row['product_uuid'];
            }
        }

        return [
            'location_gid' => $locationGid,
            'location_name' => $this->locationResolver->resolveLocationLabel(),
            'write_products_scope_ok' => $this->scopeGuard->hasWriteProductsScope(),
            'write_inventory_scope_ok' => $this->scopeGuard->hasWriteInventoryScope(),
            'write_publications_scope_ok' => $this->scopeGuard->hasWritePublicationsScope(),
            'images_enabled' => $imagesEnabled,
            'tunnel_url' => $imagesEnabled ? $tunnelUrl : null,
            'products' => $rows,
            'push_count' => count($pushUuids),
            'product_uuids' => array_values(array_unique($pushUuids)),
        ];
    }

    /**
     * @return array{
     *   location_gid: string,
     *   location_name: string|null,
     *   created: int,
     *   updated: int,
     *   failed: int,
     *   skipped: int,
     *   images_enabled: bool,
     *   errors: array<int, array{sku: string, message: string}>,
     *   collection_reorder: array{
     *     attempted: bool,
     *     collection_gid: string|null,
     *     product_count: int,
     *     moves_sent: int,
     *     job_id: string|null,
     *     skipped_reason: string|null
     *   }
     * }
     */
    public function push(string $purchaseOrderUuid): array
    {
        $preview = $this->preview($purchaseOrderUuid);
        $uuids = $preview['product_uuids'];
        if ($uuids === []) {
            return $this->emptyPushSummary($preview);
        }

        try {
            $this->scopeGuard->assertWriteProductsScope();
            $this->scopeGuard->assertWriteInventoryScope();
        } catch (ShopifyAdminConfigurationException $e) {
            throw new PurchaseOrderWorkflowPushInventoryException($e->getMessage());
        }

        /** @var \Illuminate\Support\Collection<int, Product> $products */
        $productsByUuid = $this->products->listForShopifyContentExportByUuids($uuids)->keyBy('uuid');
        $tunnelUrl = is_string($preview['tunnel_url'] ?? null) ? $preview['tunnel_url'] : null;
        $locationGid = $preview['location_gid'];

        $usedHandles = [];
        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($uuids as $uuid) {
            $product = $productsByUuid->get($uuid);
            if ($product === null) {
                continue;
            }
            try {
                $result = $this->shopifyUpsert->upsertFromProduct(
                    $product,
                    $tunnelUrl,
                    $locationGid,
                    $usedHandles,
                );
                if ($result['action'] === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'sku' => (string) $product->sku,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $collectionReorder = ['attempted' => false, 'collection_gid' => null, 'product_count' => 0, 'moves_sent' => 0, 'job_id' => null, 'skipped_reason' => 'push_had_failures'];
        if ($failed === 0) {
            try {
                $collectionReorder = $this->collectionReorder->reorderFromCatalogOrder();
            } catch (\Throwable $e) {
                $collectionReorder = [
                    'attempted' => false,
                    'collection_gid' => config('latest_arrival.collection_gid'),
                    'product_count' => 0,
                    'moves_sent' => 0,
                    'job_id' => null,
                    'skipped_reason' => 'reorder_failed: '.$e->getMessage(),
                ];
            }
        }

        return [
            'location_gid' => $locationGid,
            'location_name' => $preview['location_name'],
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => count($preview['products']) - count($uuids),
            'images_enabled' => (bool) ($preview['images_enabled'] ?? false),
            'errors' => $errors,
            'collection_reorder' => $collectionReorder,
        ];
    }

    /**
     * @param  array{
     *   location_gid: string,
     *   location_name: string|null,
     *   write_products_scope_ok: bool,
     *   write_inventory_scope_ok: bool,
     *   write_publications_scope_ok: bool,
     *   images_enabled: bool,
     *   tunnel_url: string|null,
     *   products: array<int, array<string, mixed>>,
     *   push_count: int,
     *   product_uuids: array<int, string>
     * }  $preview
     * @return array{
     *   location_gid: string,
     *   location_name: string|null,
     *   created: int,
     *   updated: int,
     *   failed: int,
     *   skipped: int,
     *   images_enabled: bool,
     *   errors: array<int, array{sku: string, message: string}>
     * }
     */
    private function emptyPushSummary(array $preview): array
    {
        return [
            'location_gid' => $preview['location_gid'],
            'location_name' => $preview['location_name'],
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped' => count($preview['products']),
            'images_enabled' => (bool) ($preview['images_enabled'] ?? false),
            'errors' => [],
            'collection_reorder' => [
                'attempted' => false,
                'collection_gid' => null,
                'product_count' => 0,
                'moves_sent' => 0,
                'job_id' => null,
                'skipped_reason' => 'no_eligible_products',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProductRow(Product $product, string $locationGid): array
    {
        $sku = trim((string) $product->sku);
        $mirror = $this->mirrorBySku->resolve($sku);
        $selling = $product->sellingPrice?->selling_price;
        $hasPrice = is_string($selling) && trim($selling) !== '';

        $storedHandle = is_string($product->handle) ? trim($product->handle) : '';
        $hasUpsertableMirror = $mirror !== null && $this->mirrorBySku->isUpsertableMirror($mirror);
        $mirrorHandle = $mirror !== null && is_string($mirror['shopify_handle'] ?? null)
            ? trim($mirror['shopify_handle'])
            : '';

        $skipReason = null;
        $action = 'create';
        if ($sku === '') {
            $skipReason = 'missing_sku';
        } elseif (! $hasPrice) {
            $skipReason = 'missing_selling_price';
        } elseif ($hasUpsertableMirror) {
            $action = 'update';
        } elseif ($storedHandle !== '') {
            $skipReason = 'missing_shopify_mirror';
        }

        $shopifyQty = null;
        if ($mirror !== null && is_string($mirror['inventory_item_gid'] ?? null) && $mirror['inventory_item_gid'] !== '') {
            $shopifyQty = $this->shopifyAvailableForItemAtLocation($mirror['inventory_item_gid'], $locationGid);
        }

        $erpAvailable = max(0, (int) ($product->available_qty ?? 0));
        $erpHold = ProductHoldQty::normalized($product->hold_qty);
        $shopifyPushQty = ProductHoldQty::sellableFromAvailable($erpAvailable, $erpHold);

        return [
            'product_uuid' => (string) $product->uuid,
            'sku' => $sku,
            'description' => (string) $product->description,
            'product_type' => is_string($product->type) && trim($product->type) !== ''
                ? trim((string) $product->type)
                : null,
            'type_label' => $this->productSort->typeLabelForProduct($product),
            'type_rank' => $this->productSort->typeRankForProduct($product),
            'product_created_at' => $this->productSort->productCreatedAtIso($product),
            'handle' => $storedHandle !== '' ? $storedHandle : ($mirrorHandle !== '' ? $mirrorHandle : null),
            'erp_available_qty' => $erpAvailable,
            'erp_hold_qty' => $erpHold,
            'shopify_push_qty' => $shopifyPushQty,
            'shopify_available_qty' => $shopifyQty,
            'selling_price' => $hasPrice ? trim((string) $selling) : null,
            'push_action' => $action,
            'push_eligible' => $skipReason === null,
            'skip_reason' => $skipReason,
        ];
    }

    private function shopifyAvailableForItemAtLocation(string $inventoryItemGid, string $locationGid): ?int
    {
        $qty = \Illuminate\Support\Facades\DB::table('shopify_inventory_levels')
            ->where('inventory_item_gid', '=', $inventoryItemGid)
            ->where('location_gid', '=', $locationGid)
            ->value('quantity_available');

        return is_numeric($qty) ? (int) $qty : null;
    }
}
