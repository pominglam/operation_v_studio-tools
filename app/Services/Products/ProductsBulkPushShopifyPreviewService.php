<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Models\Product;
use App\Services\Shopify\Admin\Write\ShopifyInventoryLocationResolver;
use App\Services\Shopify\Admin\Write\ShopifyProductMirrorBySkuResolver;
use App\Services\Shopify\Admin\Write\ShopifyWriteScopeGuard;
use App\Services\Shopify\CloudflaredTunnel;
use App\Support\Products\ProductHoldQty;
use Illuminate\Support\Facades\DB;

final class ProductsBulkPushShopifyPreviewService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly ShopifyInventoryLocationResolver $locationResolver,
        private readonly ShopifyProductMirrorBySkuResolver $mirrorBySku,
        private readonly CloudflaredTunnel $tunnel,
    ) {}

    /**
     * @param  array<int, string>  $productUuids
     * @return array{
     *   push_options: array<string, bool>,
     *   location_gid: string,
     *   location_name: string|null,
     *   write_products_scope_ok: bool,
     *   write_inventory_scope_ok: bool,
     *   write_publications_scope_ok: bool,
     *   images_enabled: bool,
     *   tunnel_url: string|null,
     *   products: array<int, array<string, mixed>>,
     *   push_count: int,
     *   create_count: int,
     *   update_count: int,
     *   skip_count: int,
     *   product_uuids: array<int, string>
     * }
     */
    public function preview(array $productUuids, ShopifyProductPushOptionsDTO $options): array
    {
        $productUuids = array_values(array_unique(array_filter(array_map('strval', $productUuids), static fn (string $v): bool => trim($v) !== '')));

        $locationGid = $this->locationResolver->resolveLocationGid();
        $tunnelStatus = $this->tunnel->status();
        $tunnelUrl = is_string($tunnelStatus['tunnel_url'] ?? null) ? trim($tunnelStatus['tunnel_url']) : '';
        $imagesEnabled = ($tunnelStatus['running'] ?? false) === true && $tunnelUrl !== '';

        $existing = $this->products->listForShopifyContentExportByUuids($productUuids);
        $mirrorsBySku = $this->mirrorBySku->resolveMany(
            $existing->pluck('sku')->filter(static fn (mixed $sku): bool => is_string($sku) && trim($sku) !== '')->map(static fn (mixed $sku): string => trim((string) $sku))->all(),
        );
        $inventoryQtyByItemGid = $this->shopifyAvailableByInventoryItemAtLocation(
            $this->inventoryItemGidsFromMirrors($mirrorsBySku),
            $locationGid,
        );

        $rows = [];
        foreach ($productUuids as $uuid) {
            $product = $existing->firstWhere('uuid', $uuid);
            if ($product === null) {
                continue;
            }
            $sku = trim((string) $product->sku);
            $mirror = $mirrorsBySku[$sku] ?? null;
            $rows[] = $this->buildProductRow($product, $locationGid, $options, $mirror, $inventoryQtyByItemGid);
        }

        return $this->summarizePreview($options, $locationGid, $imagesEnabled, $tunnelUrl, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{
     *   push_options: array<string, bool>,
     *   location_gid: string,
     *   location_name: string|null,
     *   write_products_scope_ok: bool,
     *   write_inventory_scope_ok: bool,
     *   write_publications_scope_ok: bool,
     *   images_enabled: bool,
     *   tunnel_url: string|null,
     *   products: array<int, array<string, mixed>>,
     *   push_count: int,
     *   create_count: int,
     *   update_count: int,
     *   skip_count: int,
     *   product_uuids: array<int, string>
     * }
     */
    private function summarizePreview(
        ShopifyProductPushOptionsDTO $options,
        string $locationGid,
        bool $imagesEnabled,
        ?string $tunnelUrl,
        array $rows,
    ): array {
        $pushUuids = [];
        $createCount = 0;
        $updateCount = 0;
        foreach ($rows as $row) {
            if (($row['push_eligible'] ?? false) === true) {
                $pushUuids[] = (string) $row['product_uuid'];
                if (($row['push_action'] ?? '') === 'create') {
                    $createCount++;
                } else {
                    $updateCount++;
                }
            }
        }

        return [
            'push_options' => $options->toArray(),
            'location_gid' => $locationGid,
            'location_name' => $this->locationResolver->resolveLocationLabel(),
            'write_products_scope_ok' => $this->scopeGuard->hasWriteProductsScope(),
            'write_inventory_scope_ok' => $this->scopeGuard->hasWriteInventoryScope(),
            'write_publications_scope_ok' => $this->scopeGuard->hasWritePublicationsScope(),
            'images_enabled' => $imagesEnabled,
            'tunnel_url' => $imagesEnabled ? $tunnelUrl : null,
            'products' => $rows,
            'push_count' => count($pushUuids),
            'create_count' => $createCount,
            'update_count' => $updateCount,
            'skip_count' => count($rows) - count($pushUuids),
            'product_uuids' => array_values(array_unique($pushUuids)),
        ];
    }

    /**
     * @param  array<string, array{
     *   product_gid: string,
     *   variant_gid: string,
     *   inventory_item_gid: string|null,
     *   shopify_handle: string|null,
     *   shopify_status: string|null
     * }>  $mirrorsBySku
     * @param  array<string, int|null>  $inventoryQtyByItemGid
     * @return array<string, mixed>
     */
    private function buildProductRow(
        Product $product,
        string $locationGid,
        ShopifyProductPushOptionsDTO $options,
        ?array $mirror,
        array $inventoryQtyByItemGid,
    ): array {
        $sku = trim((string) $product->sku);
        $selling = $product->sellingPrice?->selling_price;
        $hasPrice = is_string($selling) && trim($selling) !== '';

        $storedHandle = is_string($product->handle) ? trim($product->handle) : '';
        $hasUpsertableMirror = $mirror !== null && $this->mirrorBySku->isUpsertableMirror($mirror);
        $mirrorHandle = $mirror !== null && is_string($mirror['shopify_handle'] ?? null)
            ? trim($mirror['shopify_handle'])
            : '';

        $optionIndependentSkip = null;
        if ($sku === '') {
            $optionIndependentSkip = 'missing_sku';
        } elseif ($storedHandle !== '' && ! $hasUpsertableMirror) {
            $optionIndependentSkip = 'missing_shopify_mirror';
        }

        $skipReason = $this->resolveSkipReason(
            $options,
            $locationGid,
            $hasUpsertableMirror,
            $hasPrice,
            $optionIndependentSkip,
        );

        $action = $hasUpsertableMirror ? 'update' : 'create';

        $shopifyQty = null;
        if ($mirror !== null && is_string($mirror['inventory_item_gid'] ?? null) && $mirror['inventory_item_gid'] !== '') {
            $shopifyQty = $inventoryQtyByItemGid[$mirror['inventory_item_gid']] ?? null;
        }

        $erpAvailable = max(0, (int) ($product->available_qty ?? 0));
        $erpHold = ProductHoldQty::normalized($product->hold_qty);
        $shopifyPushQty = ProductHoldQty::sellableFromAvailable($erpAvailable, $erpHold);

        return [
            'product_uuid' => (string) $product->uuid,
            'sku' => $sku,
            'description' => (string) $product->description,
            'handle' => $storedHandle !== '' ? $storedHandle : ($mirrorHandle !== '' ? $mirrorHandle : null),
            'erp_available_qty' => $erpAvailable,
            'erp_hold_qty' => $erpHold,
            'shopify_push_qty' => $shopifyPushQty,
            'shopify_available_qty' => $shopifyQty,
            'selling_price' => $hasPrice ? trim((string) $selling) : null,
            'has_selling_price' => $hasPrice,
            'published_on_shopify' => (bool) ($product->published_on_shopify ?? false),
            'push_action' => $action,
            'option_independent_skip' => $optionIndependentSkip,
            'push_eligible' => $skipReason === null,
            'skip_reason' => $skipReason,
        ];
    }

    private function resolveSkipReason(
        ShopifyProductPushOptionsDTO $options,
        string $locationGid,
        bool $hasUpsertableMirror,
        bool $hasPrice,
        ?string $optionIndependentSkip,
    ): ?string {
        if ($optionIndependentSkip !== null) {
            return $optionIndependentSkip;
        }

        if (! $options->hasAny()) {
            return 'no_fields_selected';
        }

        if ($hasUpsertableMirror) {
            if ($options->price && ! $hasPrice) {
                return 'missing_selling_price';
            }
            if ($options->requiresInventoryScope() && $locationGid === '') {
                return 'missing_inventory_location';
            }

            return null;
        }

        if (! $options->info) {
            return 'create_requires_info';
        }
        if (! $options->price) {
            return 'create_requires_price';
        }
        if (! $hasPrice) {
            return 'missing_selling_price';
        }
        if ($options->requiresInventoryScope() && $locationGid === '') {
            return 'missing_inventory_location';
        }

        return null;
    }

    /**
     * @param  array<string, array{
     *   product_gid: string,
     *   variant_gid: string,
     *   inventory_item_gid: string|null,
     *   shopify_handle: string|null,
     *   shopify_status: string|null
     * }>  $mirrorsBySku
     * @return array<int, string>
     */
    private function inventoryItemGidsFromMirrors(array $mirrorsBySku): array
    {
        $gids = [];
        foreach ($mirrorsBySku as $mirror) {
            $gid = is_string($mirror['inventory_item_gid'] ?? null) ? trim($mirror['inventory_item_gid']) : '';
            if ($gid !== '') {
                $gids[] = $gid;
            }
        }

        return array_values(array_unique($gids));
    }

    /**
     * @param  array<int, string>  $inventoryItemGids
     * @return array<string, int|null>
     */
    private function shopifyAvailableByInventoryItemAtLocation(array $inventoryItemGids, string $locationGid): array
    {
        if ($locationGid === '' || $inventoryItemGids === []) {
            return [];
        }

        $rows = DB::table('shopify_inventory_levels')
            ->where('location_gid', '=', $locationGid)
            ->whereIn('inventory_item_gid', $inventoryItemGids)
            ->select(['inventory_item_gid', 'quantity_available'])
            ->get();

        $qtyByItemGid = [];
        foreach ($rows as $row) {
            $gid = is_string($row->inventory_item_gid ?? null) ? trim($row->inventory_item_gid) : '';
            if ($gid === '') {
                continue;
            }
            $qtyByItemGid[$gid] = is_numeric($row->quantity_available ?? null)
                ? (int) $row->quantity_available
                : null;
        }

        return $qtyByItemGid;
    }
}
