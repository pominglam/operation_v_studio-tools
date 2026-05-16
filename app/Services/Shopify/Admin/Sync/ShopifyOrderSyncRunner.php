<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyOrder;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;

final class ShopifyOrderSyncRunner implements ShopifySyncRunnerInterface
{
    public function __construct(
        private readonly int $pageSize,
    ) {}

    public function key(): string
    {
        return 'orders';
    }

    public function run(ShopifyAdminGraphQlClientInterface $client, ShopifySyncMetrics $metrics): void
    {
        $cursor = null;
        while (true) {
            $resp = $client->query(ShopifyAdminGraphQlQueries::ORDERS_PAGE, [
                'first' => $this->pageSize,
                'after' => $cursor,
            ]);
            $page = $resp['data']['orders'] ?? null;
            if (! is_array($page)) {
                throw new ShopifyGraphQlException('Shopify orders response missing data.orders.');
            }
            $nodes = $page['nodes'] ?? [];
            if (! is_array($nodes)) {
                throw new ShopifyGraphQlException('Shopify orders response missing nodes.');
            }
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    $metrics->recordFailure();

                    continue;
                }
                $this->upsertOrder($node, $metrics);
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
    private function upsertOrder(array $node, ShopifySyncMetrics $metrics): void
    {
        $gid = isset($node['id']) && is_string($node['id']) ? $node['id'] : null;
        if ($gid === null || $gid === '') {
            $metrics->recordFailure();

            return;
        }
        $metrics->recordFetch();
        $financial = isset($node['displayFinancialStatus']) && is_string($node['displayFinancialStatus'])
            ? $node['displayFinancialStatus'] : null;
        $fulfillment = isset($node['displayFulfillmentStatus']) && is_string($node['displayFulfillmentStatus'])
            ? $node['displayFulfillmentStatus'] : null;
        $orderedAtStr = isset($node['createdAt']) && is_string($node['createdAt']) ? $node['createdAt'] : null;
        $model = ShopifyOrder::query()->updateOrCreate(
            ['gid' => $gid],
            [
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
                'name' => isset($node['name']) && is_string($node['name']) ? $node['name'] : null,
                'display_financial_status' => $financial,
                'display_fulfillment_status' => $fulfillment,
                'ordered_at_shop_tz' => ShopifyGraphQlNodeParser::timestamp($orderedAtStr),
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($node['updatedAt']) && is_string($node['updatedAt']) ? $node['updatedAt'] : null,
                ),
                'payload_json' => $node,
            ],
        );
        $metrics->recordUpsert($model->wasRecentlyCreated);
    }
}
