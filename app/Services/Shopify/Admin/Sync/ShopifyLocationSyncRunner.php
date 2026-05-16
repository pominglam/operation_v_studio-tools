<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyLocation;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;

final class ShopifyLocationSyncRunner implements ShopifySyncRunnerInterface
{
    public function __construct(
        private readonly int $pageSize,
    ) {}

    public function key(): string
    {
        return 'locations';
    }

    public function run(ShopifyAdminGraphQlClientInterface $client, ShopifySyncMetrics $metrics): void
    {
        $cursor = null;
        while (true) {
            $resp = $client->query(ShopifyAdminGraphQlQueries::LOCATIONS_PAGE, [
                'first' => $this->pageSize,
                'after' => $cursor,
            ]);
            $page = $resp['data']['locations'] ?? null;
            if (! is_array($page)) {
                throw new ShopifyGraphQlException('Shopify locations response missing data.locations.');
            }
            $nodes = $page['nodes'] ?? [];
            if (! is_array($nodes)) {
                throw new ShopifyGraphQlException('Shopify locations response missing nodes.');
            }
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    $metrics->recordFailure();

                    continue;
                }
                $this->upsertNode($node, $metrics);
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
    private function upsertNode(array $node, ShopifySyncMetrics $metrics): void
    {
        $gid = isset($node['id']) && is_string($node['id']) ? $node['id'] : null;
        if ($gid === null || $gid === '') {
            $metrics->recordFailure();

            return;
        }
        $metrics->recordFetch();
        $model = ShopifyLocation::query()->updateOrCreate(
            ['gid' => $gid],
            [
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
                'name' => isset($node['name']) && is_string($node['name']) ? $node['name'] : null,
                'is_active' => isset($node['isActive']) ? (bool) $node['isActive'] : null,
                'fulfills_online_orders' => isset($node['fulfillsOnlineOrders']) ? (bool) $node['fulfillsOnlineOrders'] : null,
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($node['updatedAt']) && is_string($node['updatedAt']) ? $node['updatedAt'] : null,
                ),
                'payload_json' => $node,
            ],
        );
        $metrics->recordUpsert($model->wasRecentlyCreated);
    }
}
