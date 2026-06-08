<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\Shopify\ShopifySyncStateRepository;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifySyncLog;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\ShopifySettingsService;
use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;
use App\Services\Shopify\Admin\Sync\ShopifySyncMetrics;
use Illuminate\Support\Carbon;
use Throwable;

final class ShopifyOrderReconcileService
{
    private const int OVERLAP_MINUTES = 5;

    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyOrderUpsertService $upsert,
        private readonly ShopifySyncStateRepository $syncState,
        private readonly int $pageSize,
    ) {}

    public function reconcileIncremental(): ShopifySyncLog
    {
        $since = $this->watermarkSince();
        $query = $since !== null
            ? 'updated_at:>='.$since->utc()->format('Y-m-d\TH:i:s\Z')
            : null;

        return $this->runPaginated(
            syncKey: 'orders_reconcile',
            graphql: ShopifyAdminGraphQlQueries::ORDERS_INCREMENTAL_PAGE,
            queryFilter: $query,
            advanceWatermark: true,
        );
    }

    public function reconcileHistorical(?ShopifySyncLog $existingLog = null): ShopifySyncLog
    {
        return $this->runPaginated(
            syncKey: 'orders_historical',
            graphql: ShopifyAdminGraphQlQueries::ORDERS_PAGE,
            queryFilter: null,
            advanceWatermark: true,
            existingLog: $existingLog,
        );
    }

    public function fetchAndUpsertOrderGid(string $orderGid): void
    {
        $resp = $this->client->query(ShopifyAdminGraphQlQueries::ORDER_BY_ID, ['id' => $orderGid]);
        $node = $resp['data']['order'] ?? null;
        if (! is_array($node)) {
            throw new ShopifyGraphQlException('Shopify order not found for gid: '.$orderGid);
        }
        $this->upsert->upsertFromGraphQlNode($node);
    }

    private function watermarkSince(): ?Carbon
    {
        $state = $this->syncState->findByKey(ShopifySettingsService::SYNC_KEY_ORDERS);
        $anchor = $state?->last_success_at ?? $state?->high_water_updated_at;
        if ($anchor === null) {
            return null;
        }

        return $anchor->copy()->subMinutes(self::OVERLAP_MINUTES);
    }

    private function runPaginated(
        string $syncKey,
        string $graphql,
        ?string $queryFilter,
        bool $advanceWatermark,
        ?ShopifySyncLog $existingLog = null,
    ): ShopifySyncLog {
        $this->syncState->markRunStarted(ShopifySettingsService::SYNC_KEY_ORDERS);

        $started = microtime(true);
        if ($existingLog !== null) {
            $syncLog = $existingLog;
            $syncLog->forceFill([
                'sync_key' => $syncKey,
                'checkpoint_json' => array_merge($syncLog->checkpoint_json ?? [], ['query' => $queryFilter]),
            ])->save();
        } else {
            $syncLog = ShopifySyncLog::query()->create([
                'sync_key' => $syncKey,
                'status' => 'running',
                'started_at' => now(),
                'checkpoint_json' => ['query' => $queryFilter],
                'counts_json' => [],
            ]);
        }

        $metrics = new ShopifySyncMetrics;
        $maxUpdatedAtUtc = null;
        $maxUpdatedAtShopTz = null;
        $minCreatedAt = null;
        $maxCreatedAt = null;

        try {
            $cursor = null;
            while (true) {
                $variables = [
                    'first' => $this->pageSize,
                    'after' => $cursor,
                ];
                if ($graphql === ShopifyAdminGraphQlQueries::ORDERS_INCREMENTAL_PAGE) {
                    $variables['query'] = $queryFilter ?? '';
                }

                $resp = $this->client->query($graphql, $variables);
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
                    $this->upsert->upsertFromGraphQlNode($node);
                    $metrics->recordUpsert(false);

                    $updatedAtUtc = isset($node['updatedAt']) && is_string($node['updatedAt'])
                        ? Carbon::parse($node['updatedAt'])
                        : null;
                    $updatedAtShopTz = ShopifyGraphQlNodeParser::timestampInShopTz(
                        isset($node['updatedAt']) && is_string($node['updatedAt']) ? $node['updatedAt'] : null,
                    );
                    if ($updatedAtUtc !== null && ($maxUpdatedAtUtc === null || $updatedAtUtc->greaterThan($maxUpdatedAtUtc))) {
                        $maxUpdatedAtUtc = $updatedAtUtc;
                        $maxUpdatedAtShopTz = $updatedAtShopTz;
                    }

                    $createdAt = isset($node['createdAt']) && is_string($node['createdAt'])
                        ? Carbon::parse($node['createdAt'])
                        : null;
                    if ($createdAt !== null) {
                        if ($minCreatedAt === null || $createdAt->lessThan($minCreatedAt)) {
                            $minCreatedAt = $createdAt;
                        }
                        if ($maxCreatedAt === null || $createdAt->greaterThan($maxCreatedAt)) {
                            $maxCreatedAt = $createdAt;
                        }
                    }
                }

                if (! ($page['pageInfo']['hasNextPage'] ?? false)) {
                    break;
                }
                $cursor = $page['pageInfo']['endCursor'] ?? null;
                if (! is_string($cursor) || $cursor === '') {
                    break;
                }
            }

            $syncLog->status = 'completed';
            if ($advanceWatermark) {
                $this->syncState->markRunSucceeded(ShopifySettingsService::SYNC_KEY_ORDERS, $maxUpdatedAtShopTz);
            }
        } catch (Throwable $e) {
            $syncLog->status = 'failed';
            $syncLog->error_summary = mb_substr($e->getMessage(), 0, 5000);
            $this->syncState->markRunFailed(ShopifySettingsService::SYNC_KEY_ORDERS, $e->getMessage());
        }

        $counts = $metrics->toArray();
        $syncLog->records_fetched = $counts['fetched'];
        $syncLog->records_created = $counts['created'];
        $syncLog->records_updated = $counts['updated'];
        $syncLog->records_failed = $counts['failed'];
        $orderCounts = ['orders' => $counts];
        if ($minCreatedAt !== null) {
            $orderCounts['oldest_order_created_at'] = $minCreatedAt->toIso8601String();
        }
        if ($maxCreatedAt !== null) {
            $orderCounts['newest_order_created_at'] = $maxCreatedAt->toIso8601String();
        }
        $syncLog->counts_json = $orderCounts;
        $syncLog->finished_at = now();
        $syncLog->duration_ms = (int) round((microtime(true) - $started) * 1000);
        $syncLog->save();

        return $syncLog->refresh();
    }
}
