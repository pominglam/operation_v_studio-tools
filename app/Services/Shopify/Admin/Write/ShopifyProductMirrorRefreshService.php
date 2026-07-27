<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Sync\ShopifyInventoryLevelSyncRunner;
use App\Services\Shopify\Admin\Sync\ShopifyProductCatalogMirrorUpsertService;
use App\Services\Shopify\Admin\Sync\ShopifySyncMetrics;
use Illuminate\Support\Facades\Log;

final class ShopifyProductMirrorRefreshService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyProductCatalogMirrorUpsertService $mirrorUpsert,
        private readonly ShopifyInventoryLevelSyncRunner $inventoryLevelSync,
    ) {}

    public function refreshByProductGid(string $productGid, ?string $locationGid = null): void
    {
        $productGid = trim($productGid);
        if ($productGid === '') {
            throw new ShopifyGraphQlException('Cannot refresh Shopify mirror without product GID.');
        }

        $node = $this->fetchProductMirrorNodeByGid($productGid);
        $this->mirrorUpsert->upsertProductNode($node);

        $this->refreshInventoryLevelsForNode($node, $locationGid);

        Log::channel('shopify')->info('shopify.write.product_mirror.refreshed', [
            'product_gid' => $productGid,
            'sku' => $this->firstVariantSku($node),
            'inventory_refresh' => $locationGid !== null && trim($locationGid) !== '',
        ]);
    }

    public function tryLinkBySku(string $sku): bool
    {
        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }

        $node = $this->fetchProductMirrorNodeBySearch('sku:'.str_replace('"', '\\"', $sku));
        if ($node === null) {
            return false;
        }

        $this->mirrorUpsert->upsertProductNode($node);

        Log::channel('shopify')->info('shopify.write.product_mirror.linked_by_sku', [
            'sku' => $sku,
            'product_gid' => is_string($node['id'] ?? null) ? $node['id'] : null,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProductMirrorNodeByGid(string $productGid): array
    {
        $response = $this->client->query(ShopifyAdminGraphQlQueries::PRODUCT_MIRROR_BY_ID, [
            'id' => $productGid,
        ]);

        $node = is_array($response['data']['product'] ?? null) ? $response['data']['product'] : null;
        if ($node === null) {
            throw new ShopifyGraphQlException(sprintf(
                'Shopify product mirror refresh failed: product %s not found.',
                $productGid,
            ));
        }

        return $node;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProductMirrorNodeBySearch(string $searchQuery): ?array
    {
        $response = $this->client->query(ShopifyAdminGraphQlQueries::PRODUCT_MIRROR_SEARCH, [
            'query' => $searchQuery,
        ]);

        $nodes = is_array($response['data']['products']['nodes'] ?? null)
            ? $response['data']['products']['nodes']
            : [];
        $node = is_array($nodes[0] ?? null) ? $nodes[0] : null;

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function refreshInventoryLevelsForNode(array $node, ?string $locationGid): void
    {
        $locationGid = is_string($locationGid) ? trim($locationGid) : '';
        if ($locationGid === '') {
            return;
        }

        $inventoryItemGid = $this->firstInventoryItemGid($node);
        if ($inventoryItemGid === null) {
            return;
        }

        $metrics = new ShopifySyncMetrics;
        $this->inventoryLevelSync->syncInventoryItemGids($this->client, [$inventoryItemGid], $metrics);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function firstInventoryItemGid(array $node): ?string
    {
        $variants = is_array($node['variants']['nodes'] ?? null) ? $node['variants']['nodes'] : [];
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $inv = $variant['inventoryItem'] ?? null;
            if (! is_array($inv)) {
                continue;
            }

            $gid = is_string($inv['id'] ?? null) ? trim($inv['id']) : '';
            if ($gid !== '') {
                return $gid;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function firstVariantSku(array $node): ?string
    {
        $variants = is_array($node['variants']['nodes'] ?? null) ? $node['variants']['nodes'] : [];
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $sku = is_string($variant['sku'] ?? null) ? trim($variant['sku']) : '';
            if ($sku !== '') {
                return $sku;
            }
        }

        return null;
    }
}
