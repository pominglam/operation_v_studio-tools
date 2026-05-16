<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyCollection;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;

final class ShopifyCollectionSyncRunner implements ShopifySyncRunnerInterface
{
    public function __construct(
        private readonly int $pageSize,
    ) {}

    public function key(): string
    {
        return 'collections';
    }

    public function run(ShopifyAdminGraphQlClientInterface $client, ShopifySyncMetrics $metrics): void
    {
        $cursor = null;
        while (true) {
            $resp = $client->query(ShopifyAdminGraphQlQueries::COLLECTIONS_PAGE, [
                'first' => $this->pageSize,
                'after' => $cursor,
            ]);
            $page = $resp['data']['collections'] ?? null;
            if (! is_array($page)) {
                throw new ShopifyGraphQlException('Shopify collections response missing data.collections.');
            }
            $nodes = $page['nodes'] ?? [];
            if (! is_array($nodes)) {
                throw new ShopifyGraphQlException('Shopify collections response missing nodes.');
            }
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    $metrics->recordFailure();

                    continue;
                }
                $this->upsertCollection($node, $metrics);
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
    private function upsertCollection(array $node, ShopifySyncMetrics $metrics): void
    {
        $gid = isset($node['id']) && is_string($node['id']) ? $node['id'] : null;
        if ($gid === null || $gid === '') {
            $metrics->recordFailure();

            return;
        }
        $metrics->recordFetch();
        $model = ShopifyCollection::query()->updateOrCreate(
            ['gid' => $gid],
            [
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
                'handle' => isset($node['handle']) && is_string($node['handle']) ? $node['handle'] : null,
                'title' => isset($node['title']) && is_string($node['title']) ? $node['title'] : null,
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($node['updatedAt']) && is_string($node['updatedAt']) ? $node['updatedAt'] : null,
                ),
                'payload_json' => $node,
            ],
        );
        $metrics->recordUpsert($model->wasRecentlyCreated);
    }
}
