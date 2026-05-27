<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Orders\ShopifyOrderUpsertService;

final class ShopifyOrderSyncRunner implements ShopifySyncRunnerInterface
{
    public function __construct(
        private readonly int $pageSize,
        private readonly ShopifyOrderUpsertService $upsert,
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
                $metrics->recordFetch();
                $order = $this->upsert->upsertFromGraphQlNode($node);
                $metrics->recordUpsert($order->wasRecentlyCreated);
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
}
