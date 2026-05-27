<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Products\ProductRepository;
use App\Services\Shopify\Admin\Sync\ShopifyErpSyncCoordinator;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderWorkflowPullHandlesService
{
    public function __construct(
        private readonly PurchaseOrderProductScopeService $scope,
        private readonly ProductRepository $products,
        private readonly ShopifyErpSyncCoordinator $shopifySync,
    ) {}

    /**
     * @return array{
     *   products: array<int, array<string, mixed>>,
     *   pull_count: int,
     *   already_has_handle_count: int,
     *   product_uuids: array<int, string>
     * }
     */
    public function preview(string $purchaseOrderUuid): array
    {
        $newProducts = $this->scope->productsForPo($purchaseOrderUuid, false);

        $rows = [];
        $pullUuids = [];
        $alreadyHasHandle = 0;

        foreach ($newProducts as $product) {
            $handle = is_string($product->handle ?? null) ? trim($product->handle) : '';
            if ($handle !== '') {
                $alreadyHasHandle++;

                continue;
            }

            $sku = trim((string) $product->sku);
            $mirrorHandle = $sku !== '' ? $this->lookupHandleBySku($sku) : null;

            $rows[] = [
                'product_uuid' => (string) $product->uuid,
                'sku' => $sku,
                'description' => (string) $product->description,
                'handle' => null,
                'mirror_handle' => $mirrorHandle !== null && trim($mirrorHandle) !== '' ? trim($mirrorHandle) : null,
            ];
            $pullUuids[] = (string) $product->uuid;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? '')),
        );

        return [
            'products' => $rows,
            'pull_count' => count($pullUuids),
            'already_has_handle_count' => $alreadyHasHandle,
            'product_uuids' => array_values(array_unique($pullUuids)),
        ];
    }

    /**
     * @return array{
     *   sync_status: string,
     *   updated: int,
     *   skipped_already_has_handle: int,
     *   missing_in_shopify: array<int, string>
     * }
     */
    public function pullHandles(string $purchaseOrderUuid): array
    {
        $syncLog = $this->shopifySync->sync('products');
        if ($syncLog->status !== 'completed') {
            throw new \RuntimeException('Shopify product sync failed: '.(string) ($syncLog->error_summary ?? 'unknown error'));
        }

        return $this->applyHandlesFromMirror($purchaseOrderUuid);
    }

    /**
     * @return array{
     *   sync_status: string,
     *   updated: int,
     *   skipped_already_has_handle: int,
     *   missing_in_shopify: array<int, string>
     * }
     */
    public function applyHandlesFromMirror(string $purchaseOrderUuid): array
    {
        $newProducts = $this->scope->productsForPo($purchaseOrderUuid, false);

        $updated = 0;
        $skipped = 0;
        $missingInShopify = [];

        foreach ($newProducts as $product) {
            $handle = is_string($product->handle ?? null) ? trim($product->handle) : '';
            if ($handle !== '') {
                $skipped++;

                continue;
            }

            $sku = trim((string) $product->sku);
            if ($sku === '') {
                $missingInShopify[] = '(empty sku)';

                continue;
            }

            $shopifyHandle = $this->lookupHandleBySku($sku);
            if ($shopifyHandle === null || trim($shopifyHandle) === '') {
                $missingInShopify[] = $sku;

                continue;
            }

            $product->handle = trim($shopifyHandle);
            $this->products->save($product);
            $updated++;
        }

        return [
            'sync_status' => 'completed',
            'updated' => $updated,
            'skipped_already_has_handle' => $skipped,
            'missing_in_shopify' => $missingInShopify,
        ];
    }

    private function lookupHandleBySku(string $sku): ?string
    {
        /** @var object{handle:?string}|null $row */
        $row = DB::table('shopify_product_variants as spv')
            ->join('shopify_products as sp', 'sp.gid', '=', 'spv.product_gid')
            ->where('spv.sku', '=', $sku)
            ->select('sp.handle')
            ->first();

        if ($row === null) {
            return null;
        }

        $handle = $row->handle;

        return is_string($handle) ? $handle : null;
    }
}
