<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyInventoryItem;
use App\Models\Shopify\ShopifyProduct;
use App\Models\Shopify\ShopifyProductVariant;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;

final class ShopifyProductCatalogSyncRunner implements ShopifySyncRunnerInterface
{
    public function __construct(
        private readonly int $pageSize,
    ) {}

    public function key(): string
    {
        return 'products';
    }

    public function run(ShopifyAdminGraphQlClientInterface $client, ShopifySyncMetrics $metrics): void
    {
        $cursor = null;
        while (true) {
            $resp = $client->query(ShopifyAdminGraphQlQueries::PRODUCTS_PAGE, [
                'first' => $this->pageSize,
                'after' => $cursor,
            ]);
            $page = $resp['data']['products'] ?? null;
            if (! is_array($page)) {
                throw new ShopifyGraphQlException('Shopify products response missing data.products.');
            }
            $nodes = $page['nodes'] ?? [];
            if (! is_array($nodes)) {
                throw new ShopifyGraphQlException('Shopify products response missing nodes.');
            }
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    $metrics->recordFailure();

                    continue;
                }
                $this->upsertProductWithVariants($node, $metrics);
            }
            if (! ($page['pageInfo']['hasNextPage'] ?? false)) {
                break;
            }
            $cursor = $page['pageInfo']['endCursor'] ?? null;
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function upsertProductWithVariants(array $node, ShopifySyncMetrics $metrics): void
    {
        $productGid = isset($node['id']) && is_string($node['id']) ? $node['id'] : null;
        if ($productGid === null || $productGid === '') {
            $metrics->recordFailure();

            return;
        }
        $metrics->recordFetch();
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
        $metrics->recordUpsert($product->wasRecentlyCreated);

        $variants = $node['variants']['nodes'] ?? [];
        if (! is_array($variants)) {
            return;
        }
        foreach ($variants as $v) {
            if (! is_array($v)) {
                $metrics->recordFailure();

                continue;
            }
            $this->upsertVariant($productGid, $v, $metrics);
        }
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function upsertVariant(string $productGid, array $v, ShopifySyncMetrics $metrics): void
    {
        $gid = isset($v['id']) && is_string($v['id']) ? $v['id'] : null;
        if ($gid === null || $gid === '') {
            $metrics->recordFailure();

            return;
        }
        $invItemGid = null;
        $inv = $v['inventoryItem'] ?? null;
        if (is_array($inv) && isset($inv['id']) && is_string($inv['id'])) {
            $invItemGid = $inv['id'];
        }
        $metrics->recordFetch();
        $variant = ShopifyProductVariant::query()->updateOrCreate(
            ['gid' => $gid],
            [
                'product_gid' => $productGid,
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($v['legacyResourceId'] ?? null),
                'sku' => isset($v['sku']) && is_string($v['sku']) ? $v['sku'] : null,
                'barcode' => isset($v['barcode']) && is_string($v['barcode']) ? $v['barcode'] : null,
                'inventory_quantity' => isset($v['inventoryQuantity']) && is_numeric($v['inventoryQuantity'])
                    ? (int) $v['inventoryQuantity'] : null,
                'inventory_item_gid' => $invItemGid,
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($v['updatedAt']) && is_string($v['updatedAt']) ? $v['updatedAt'] : null,
                ),
                'payload_json' => $v,
            ],
        );
        $metrics->recordUpsert($variant->wasRecentlyCreated);

        if (is_array($inv) && isset($inv['id']) && is_string($inv['id'])) {
            $this->upsertInventoryItem($inv, $metrics);
        }
    }

    /**
     * @param  array<string, mixed>  $inv
     */
    private function upsertInventoryItem(array $inv, ShopifySyncMetrics $metrics): void
    {
        $gid = $inv['id'];
        $metrics->recordFetch();
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
        $metrics->recordUpsert($item->wasRecentlyCreated);
    }
}
