<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyCustomer;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;

final class ShopifyCustomerSyncRunner implements ShopifySyncRunnerInterface
{
    public function __construct(
        private readonly int $pageSize,
    ) {}

    public function key(): string
    {
        return 'customers';
    }

    public function run(ShopifyAdminGraphQlClientInterface $client, ShopifySyncMetrics $metrics): void
    {
        $cursor = null;
        while (true) {
            $resp = $client->query(ShopifyAdminGraphQlQueries::CUSTOMERS_PAGE, [
                'first' => $this->pageSize,
                'after' => $cursor,
            ]);
            $page = $resp['data']['customers'] ?? null;
            if (! is_array($page)) {
                throw new ShopifyGraphQlException('Shopify customers response missing data.customers.');
            }
            $nodes = $page['nodes'] ?? [];
            if (! is_array($nodes)) {
                throw new ShopifyGraphQlException('Shopify customers response missing nodes.');
            }
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    $metrics->recordFailure();

                    continue;
                }
                $this->upsertCustomer($node, $metrics);
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
    private function upsertCustomer(array $node, ShopifySyncMetrics $metrics): void
    {
        $gid = isset($node['id']) && is_string($node['id']) ? $node['id'] : null;
        if ($gid === null || $gid === '') {
            $metrics->recordFailure();

            return;
        }
        $email = null;
        $dea = $node['defaultEmailAddress'] ?? null;
        if (is_array($dea) && isset($dea['emailAddress']) && is_string($dea['emailAddress'])) {
            $email = $dea['emailAddress'];
        }
        $metrics->recordFetch();
        $model = ShopifyCustomer::query()->updateOrCreate(
            ['gid' => $gid],
            [
                'legacy_numeric_id' => ShopifyGraphQlNodeParser::legacyString($node['legacyResourceId'] ?? null),
                'display_name' => isset($node['displayName']) && is_string($node['displayName'])
                    ? $node['displayName'] : null,
                'email' => $email,
                'customer_created_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($node['createdAt']) && is_string($node['createdAt']) ? $node['createdAt'] : null,
                ),
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($node['updatedAt']) && is_string($node['updatedAt']) ? $node['updatedAt'] : null,
                ),
                'payload_json' => $node,
            ],
        );
        $metrics->recordUpsert($model->wasRecentlyCreated);
    }
}
