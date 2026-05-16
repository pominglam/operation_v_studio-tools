<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Sync;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyInventoryItem;
use App\Models\Shopify\ShopifyInventoryLevel;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;

final class ShopifyInventoryLevelSyncRunner implements ShopifySyncRunnerInterface
{
    public function __construct(
        private readonly int $pageSize,
    ) {}

    public function key(): string
    {
        return 'inventory_levels';
    }

    public function run(ShopifyAdminGraphQlClientInterface $client, ShopifySyncMetrics $metrics): void
    {
        $gids = ShopifyInventoryItem::query()
            ->orderBy('gid')
            ->pluck('gid');

        foreach ($gids as $itemGid) {
            if (! is_string($itemGid) || $itemGid === '') {
                continue;
            }
            $cursor = null;
            while (true) {
                $resp = $client->query(ShopifyAdminGraphQlQueries::INVENTORY_ITEM_LEVELS, [
                    'id' => $itemGid,
                    'first' => $this->pageSize,
                    'after' => $cursor,
                ]);
                $root = $resp['data']['inventoryItem'] ?? null;
                if ($root === null) {
                    break;
                }
                if (! is_array($root)) {
                    throw new ShopifyGraphQlException('Shopify inventoryItem response malformed.');
                }
                $levels = $root['inventoryLevels'] ?? null;
                if (! is_array($levels)) {
                    break;
                }
                $nodes = $levels['nodes'] ?? [];
                if (! is_array($nodes)) {
                    throw new ShopifyGraphQlException('Shopify inventory levels missing nodes.');
                }
                foreach ($nodes as $lvl) {
                    if (! is_array($lvl)) {
                        $metrics->recordFailure();

                        continue;
                    }
                    $this->upsertLevel($itemGid, $lvl, $metrics);
                }
                if (! ($levels['pageInfo']['hasNextPage'] ?? false)) {
                    break;
                }
                $cursor = $levels['pageInfo']['endCursor'] ?? null;
                if (! is_string($cursor) || $cursor === '') {
                    break;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $lvl
     */
    private function upsertLevel(string $itemGid, array $lvl, ShopifySyncMetrics $metrics): void
    {
        $loc = $lvl['location'] ?? null;
        $locationGid = is_array($loc) && isset($loc['id']) && is_string($loc['id']) ? $loc['id'] : null;
        if ($locationGid === null || $locationGid === '') {
            $metrics->recordFailure();

            return;
        }
        $levelGid = isset($lvl['id']) && is_string($lvl['id']) ? $lvl['id'] : null;
        $qty = $this->availableQuantity($lvl['quantities'] ?? null);
        $metrics->recordFetch();
        $model = ShopifyInventoryLevel::query()->updateOrCreate(
            [
                'inventory_item_gid' => $itemGid,
                'location_gid' => $locationGid,
            ],
            [
                'quantity_available' => $qty,
                'level_gid' => $levelGid,
                'graphql_updated_at' => ShopifyGraphQlNodeParser::timestamp(
                    isset($lvl['updatedAt']) && is_string($lvl['updatedAt']) ? $lvl['updatedAt'] : null,
                ),
                'payload_json' => $lvl,
            ],
        );
        $metrics->recordUpsert($model->wasRecentlyCreated);
    }

    private function availableQuantity(mixed $quantities): ?int
    {
        if (! is_array($quantities)) {
            return null;
        }
        foreach ($quantities as $q) {
            if (! is_array($q)) {
                continue;
            }
            $name = isset($q['name']) && is_string($q['name']) ? strtolower($q['name']) : '';
            if ($name === '' || $name !== 'available') {
                continue;
            }
            if (array_key_exists('quantity', $q) && is_numeric($q['quantity'])) {
                return (int) $q['quantity'];
            }
        }

        return null;
    }
}
