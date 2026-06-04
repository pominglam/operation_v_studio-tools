<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use Illuminate\Support\Facades\DB;

final class ShopifyProductMirrorBySkuResolver
{
    /** @var array<int, string> */
    private const UPSERTABLE_STATUSES = ['ACTIVE', 'DRAFT'];

    /**
     * @param  array{
     *   product_gid: string,
     *   variant_gid: string,
     *   inventory_item_gid: string|null,
     *   shopify_handle: string|null,
     *   shopify_status: string|null
     * }  $mirror
     */
    public function isUpsertableMirror(array $mirror): bool
    {
        $status = strtoupper(trim((string) ($mirror['shopify_status'] ?? '')));

        return $status !== '' && in_array($status, self::UPSERTABLE_STATUSES, true);
    }

    /**
     * @return array{
     *   product_gid: string,
     *   variant_gid: string,
     *   inventory_item_gid: string|null,
     *   shopify_handle: string|null,
     *   shopify_status: string|null
     * }|null
     */
    public function resolve(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        /** @var object{
         *   product_gid:?string,
         *   variant_gid:?string,
         *   inventory_item_gid:?string,
         *   shopify_handle:?string,
         *   shopify_status:?string
         * }|null $row */
        $row = DB::table('shopify_product_variants as spv')
            ->join('shopify_products as sp', 'sp.gid', '=', 'spv.product_gid')
            ->where('spv.sku', '=', $sku)
            ->select([
                'sp.gid as product_gid',
                'spv.gid as variant_gid',
                'spv.inventory_item_gid',
                'sp.handle as shopify_handle',
                'sp.status as shopify_status',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        $productGid = is_string($row->product_gid ?? null) ? trim($row->product_gid) : '';
        $variantGid = is_string($row->variant_gid ?? null) ? trim($row->variant_gid) : '';
        if ($productGid === '' || $variantGid === '') {
            return null;
        }

        $inventoryItemGid = is_string($row->inventory_item_gid ?? null) ? trim($row->inventory_item_gid) : '';

        return [
            'product_gid' => $productGid,
            'variant_gid' => $variantGid,
            'inventory_item_gid' => $inventoryItemGid !== '' ? $inventoryItemGid : null,
            'shopify_handle' => is_string($row->shopify_handle ?? null) ? trim($row->shopify_handle) : null,
            'shopify_status' => is_string($row->shopify_status ?? null) ? trim($row->shopify_status) : null,
        ];
    }
}
