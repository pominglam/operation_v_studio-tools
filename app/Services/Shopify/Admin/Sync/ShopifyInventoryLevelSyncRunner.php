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
    private const int LEVELS_PAGE_SIZE = 10;

    public function __construct(
        private readonly int $pageSize,
        private readonly int $itemBatchSize = 200,
    ) {}

    public function key(): string
    {
        return 'inventory_levels';
    }

    public function run(ShopifyAdminGraphQlClientInterface $client, ShopifySyncMetrics $metrics): void
    {
        $gids = ShopifyInventoryItem::query()
            ->orderBy('gid')
            ->pluck('gid')
            ->all();

        $this->syncInventoryItemGids($client, $gids, $metrics);
    }

    /**
     * @param  array<int, string>  $inventoryItemGids
     */
    public function syncInventoryItemGids(
        ShopifyAdminGraphQlClientInterface $client,
        array $inventoryItemGids,
        ShopifySyncMetrics $metrics,
    ): void {
        $gids = [];
        foreach ($inventoryItemGids as $itemGid) {
            if (is_string($itemGid) && $itemGid !== '') {
                $gids[] = $itemGid;
            }
        }

        foreach (array_chunk(array_values(array_unique($gids)), $this->resolvedItemBatchSize()) as $chunk) {
            $this->syncInventoryItemBatch($client, $chunk, $metrics);
        }
    }

    /**
     * @param  list<string>  $itemGids
     */
    private function syncInventoryItemBatch(
        ShopifyAdminGraphQlClientInterface $client,
        array $itemGids,
        ShopifySyncMetrics $metrics,
    ): void {
        $resp = $client->query(ShopifyAdminGraphQlQueries::INVENTORY_ITEMS_BY_IDS, [
            'ids' => $itemGids,
            'levelsFirst' => $this->levelsPageSize(),
        ]);
        $nodes = $resp['data']['nodes'] ?? null;
        if (! is_array($nodes)) {
            throw new ShopifyGraphQlException('Shopify inventory items batch response missing data.nodes.');
        }
        foreach ($nodes as $node) {
            $this->ingestInventoryItemNode($client, $node, $metrics);
        }
    }

    private function ingestInventoryItemNode(
        ShopifyAdminGraphQlClientInterface $client,
        mixed $node,
        ShopifySyncMetrics $metrics,
    ): void {
        if (! is_array($node)) {
            return;
        }
        $itemGid = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
        if ($itemGid === '') {
            $metrics->recordFailure();

            return;
        }
        $cursor = $this->ingestLevelsConnection($itemGid, $node['inventoryLevels'] ?? null, $metrics);
        if ($cursor !== null) {
            $this->paginateRemainingLevels($client, $itemGid, $cursor, $metrics);
        }
    }

    private function paginateRemainingLevels(
        ShopifyAdminGraphQlClientInterface $client,
        string $itemGid,
        string $after,
        ShopifySyncMetrics $metrics,
    ): void {
        $cursor = $after;
        while (true) {
            $resp = $client->query(ShopifyAdminGraphQlQueries::INVENTORY_ITEM_LEVELS, [
                'id' => $itemGid,
                'first' => $this->levelsPageSize(),
                'after' => $cursor,
            ]);
            $root = $resp['data']['inventoryItem'] ?? null;
            if (! is_array($root)) {
                break;
            }
            $cursor = $this->ingestLevelsConnection($itemGid, $root['inventoryLevels'] ?? null, $metrics);
            if ($cursor === null) {
                break;
            }
        }
    }

    private function ingestLevelsConnection(string $itemGid, mixed $levels, ShopifySyncMetrics $metrics): ?string
    {
        if (! is_array($levels)) {
            return null;
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
            return null;
        }
        $cursor = $levels['pageInfo']['endCursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
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

    private function resolvedItemBatchSize(): int
    {
        return max(1, min(250, $this->itemBatchSize));
    }

    private function levelsPageSize(): int
    {
        return max(1, min(self::LEVELS_PAGE_SIZE, $this->pageSize));
    }
}
