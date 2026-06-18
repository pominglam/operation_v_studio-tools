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

        return $this->resolveMany([$sku])[$sku] ?? null;
    }

    /**
     * @param  array<int, string>  $skus
     * @return array<string, array{
     *   product_gid: string,
     *   variant_gid: string,
     *   inventory_item_gid: string|null,
     *   shopify_handle: string|null,
     *   shopify_status: string|null
     * }>
     */
    public function resolveMany(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(
            static fn (mixed $sku): string => trim((string) $sku),
            $skus,
        ), static fn (string $sku): bool => $sku !== '')));

        if ($skus === []) {
            return [];
        }

        $rows = DB::table('shopify_product_variants as spv')
            ->join('shopify_products as sp', 'sp.gid', '=', 'spv.product_gid')
            ->whereIn('spv.sku', $skus)
            ->select([
                'spv.sku',
                'sp.gid as product_gid',
                'spv.gid as variant_gid',
                'spv.inventory_item_gid',
                'sp.handle as shopify_handle',
                'sp.status as shopify_status',
            ])
            ->get();

        $mirrors = [];
        foreach ($rows as $row) {
            $sku = is_string($row->sku ?? null) ? trim($row->sku) : '';
            if ($sku === '' || isset($mirrors[$sku])) {
                continue;
            }

            $productGid = is_string($row->product_gid ?? null) ? trim($row->product_gid) : '';
            $variantGid = is_string($row->variant_gid ?? null) ? trim($row->variant_gid) : '';
            if ($productGid === '' || $variantGid === '') {
                continue;
            }

            $inventoryItemGid = is_string($row->inventory_item_gid ?? null) ? trim($row->inventory_item_gid) : '';

            $mirrors[$sku] = [
                'product_gid' => $productGid,
                'variant_gid' => $variantGid,
                'inventory_item_gid' => $inventoryItemGid !== '' ? $inventoryItemGid : null,
                'shopify_handle' => is_string($row->shopify_handle ?? null) ? trim($row->shopify_handle) : null,
                'shopify_status' => is_string($row->shopify_status ?? null) ? trim($row->shopify_status) : null,
            ];
        }

        return $mirrors;
    }
}
