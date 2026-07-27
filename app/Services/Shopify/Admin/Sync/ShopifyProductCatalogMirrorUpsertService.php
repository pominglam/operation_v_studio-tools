<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Models\Shopify\ShopifyInventoryItem;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;

final class ShopifyProductCatalogMirrorUpsertService
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function upsertProductNode(array $node, ?ShopifySyncMetrics $metrics = null): void
    {
        $productGid = isset($node['id']) && is_string($node['id']) ? trim($node['id']) : '';
        if ($productGid === '') {
            $metrics?->recordFailure();

            return;
        }

        $metrics?->recordFetch();
        $product = ShopifyProduct::query()->updateOrCreate(
            ['gid' => $productGid],
            [
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
                'handle' => isset($node['handle']) && is_string($node['handle']) ? $node['handle'] : null,
                'title' => isset($node['title']) && is_string($node['title']) ? $node['title'] : null,
                'status' => isset($node['status']) && is_string($node['status']) ? $node['status'] : null,
                'vendor' => isset($node['vendor']) && is_string($node['vendor']) ? $node['vendor'] : null,
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($node['updatedAt']) && is_string($node['updatedAt']) ? $node['updatedAt'] : null,
                ),
                'payload_json' => $node,
            ],
        );
        $metrics?->recordUpsert($product->wasRecentlyCreated);

        $variants = $node['variants']['nodes'] ?? [];
        if (! is_array($variants)) {
            return;
        }

        foreach ($variants as $variantNode) {
            if (! is_array($variantNode)) {
                $metrics?->recordFailure();

                continue;
            }

            $this->upsertVariantNode($productGid, $variantNode, $metrics);
        }
    }

    /**
     * @param  array<string, mixed>  $variantNode
     */
    private function upsertVariantNode(string $productGid, array $variantNode, ?ShopifySyncMetrics $metrics): void
    {
        $gid = isset($variantNode['id']) && is_string($variantNode['id']) ? trim($variantNode['id']) : '';
        if ($gid === '') {
            $metrics?->recordFailure();

            return;
        }

        $invItemGid = null;
        $inv = $variantNode['inventoryItem'] ?? null;
        if (is_array($inv) && isset($inv['id']) && is_string($inv['id'])) {
            $invItemGid = trim($inv['id']);
        }

        $metrics?->recordFetch();
        $variant = ShopifyProductVariant::query()->updateOrCreate(
            ['gid' => $gid],
            [
                'product_gid' => $productGid,
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($variantNode['legacyResourceId'] ?? null),
                'sku' => isset($variantNode['sku']) && is_string($variantNode['sku']) ? $variantNode['sku'] : null,
                'barcode' => isset($variantNode['barcode']) && is_string($variantNode['barcode']) ? $variantNode['barcode'] : null,
                'inventory_quantity' => isset($variantNode['inventoryQuantity']) && is_numeric($variantNode['inventoryQuantity'])
                    ? (int) $variantNode['inventoryQuantity'] : null,
                'inventory_item_gid' => $invItemGid !== '' ? $invItemGid : null,
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($variantNode['updatedAt']) && is_string($variantNode['updatedAt']) ? $variantNode['updatedAt'] : null,
                ),
                'payload_json' => $variantNode,
            ],
        );
        $metrics?->recordUpsert($variant->wasRecentlyCreated);

        if (is_array($inv) && isset($inv['id']) && is_string($inv['id']) && trim($inv['id']) !== '') {
            $this->upsertInventoryItemNode($inv, $metrics);
        }
    }

    /**
     * @param  array<string, mixed>  $inv
     */
    private function upsertInventoryItemNode(array $inv, ?ShopifySyncMetrics $metrics): void
    {
        $gid = isset($inv['id']) && is_string($inv['id']) ? trim($inv['id']) : '';
        if ($gid === '') {
            $metrics?->recordFailure();

            return;
        }

        $metrics?->recordFetch();
        $item = ShopifyInventoryItem::query()->updateOrCreate(
            ['gid' => $gid],
            [
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($inv['legacyResourceId'] ?? null),
                'sku' => isset($inv['sku']) && is_string($inv['sku']) ? $inv['sku'] : null,
                'tracked' => array_key_exists('tracked', $inv) ? (bool) $inv['tracked'] : null,
                'requires_shipping' => array_key_exists('requiresShipping', $inv)
                    ? (bool) $inv['requiresShipping'] : null,
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($inv['updatedAt']) && is_string($inv['updatedAt']) ? $inv['updatedAt'] : null,
                ),
                'payload_json' => $inv,
            ],
        );
        $metrics?->recordUpsert($item->wasRecentlyCreated);
    }
}
