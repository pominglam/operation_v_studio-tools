<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class ShopifyStaffOrdersMonthlyReportService
{
    private const string ORDERS_QUERY = <<<'GQL'
query StaffOrdersReport($first: Int!, $after: String, $query: String) {
  orders(first: $first, after: $after, sortKey: CREATED_AT, reverse: false, query: $query) {
    pageInfo { hasNextPage endCursor }
    nodes {
      legacyResourceId
      createdAt
      sourceName
      cancelledAt
      displayFinancialStatus
      channelInformation { channelDefinition { channelName } }
    }
  }
}
GQL;

    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyOrderStaffBucketClassifier $classifier,
        private readonly ShopifyOrderPosUserIdFetcher $userIdFetcher,
        private readonly ShopifyOrderDemandEligibility $eligibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reportForMonth(string $month): array
    {
        $month = trim($month);
        $cacheKey = 'staff_orders_report:'.$month;
        $ttl = (int) config('shopify.staff_order_report.cache_ttl_seconds', 300);

        /** @var array<string, mixed> $report */
        $report = Cache::remember($cacheKey, $ttl, fn (): array => $this->buildReport($month));

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(string $month): array
    {
        $timezone = (string) config('shopify.staff_order_report.timezone', 'America/Toronto');
        [$rangeStart, $rangeEnd, $daysInMonth] = $this->monthRange($month, $timezone);
        $columns = $this->reportColumns();
        $bucketKeys = array_map(static fn (array $column): string => $column['key'], $columns);
        $bucketKeys = array_values(array_filter($bucketKeys, static fn (string $key): bool => $key !== 'date' && $key !== 'total'));

        $daily = [];
        foreach ($daysInMonth as $day) {
            $daily[$day] = array_fill_keys($bucketKeys, 0);
            $daily[$day]['total'] = 0;
        }

        $ordersScanned = 0;
        $after = null;
        $queryFilter = sprintf(
            'created_at:>=%s created_at:<%s',
            $rangeStart->format('Y-m-d'),
            $rangeEnd->format('Y-m-d'),
        );
        $staffByUserId = $this->staffByUserIdMap();

        do {
            $response = $this->client->query(self::ORDERS_QUERY, [
                'first' => 50,
                'after' => $after,
                'query' => $queryFilter,
            ]);
            $page = is_array($response['data']['orders'] ?? null) ? $response['data']['orders'] : [];
            $nodes = is_array($page['nodes'] ?? null) ? $page['nodes'] : [];

            foreach ($nodes as $node) {
                if (! is_array($node) || ! $this->eligibility->isEligibleFromGraphQlNode($node)) {
                    continue;
                }

                $bucket = $this->resolveBucket($node, $staffByUserId);
                $day = $this->orderDay($node, $timezone);
                if ($day === null || ! isset($daily[$day]) || ! isset($daily[$day][$bucket])) {
                    continue;
                }

                $ordersScanned++;
                $daily[$day][$bucket]++;
                $daily[$day]['total']++;
            }

            $hasNext = ($page['pageInfo']['hasNextPage'] ?? false) === true;
            $after = $hasNext && is_string($page['pageInfo']['endCursor'] ?? null)
                ? $page['pageInfo']['endCursor']
                : null;
        } while ($after !== null);

        $totals = array_fill_keys($bucketKeys, 0);
        $totals['total'] = 0;
        foreach ($daily as $counts) {
            foreach ($bucketKeys as $key) {
                $totals[$key] += (int) ($counts[$key] ?? 0);
            }
            $totals['total'] += (int) ($counts['total'] ?? 0);
        }

        $rows = [];
        foreach ($daysInMonth as $day) {
            $rows[] = array_merge(['date' => $day], $daily[$day]);
        }

        return [
            'month' => $month,
            'timezone' => $timezone,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
            'orders_scanned' => $ordersScanned,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: list<string>}
     */
    private function monthRange(string $month, string $timezone): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', $timezone)->startOfDay();
        if ($start === false) {
            throw new \InvalidArgumentException('Invalid month.');
        }
        $end = $start->addMonth();
        $days = [];
        for ($cursor = $start; $cursor->lt($end); $cursor = $cursor->addDay()) {
            $days[] = $cursor->format('Y-m-d');
        }

        return [$start, $end, $days];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function reportColumns(): array
    {
        $columns = [['key' => 'date', 'label' => 'Date']];
        foreach ($this->staffByUserIdMap() as $staff) {
            $columns[] = ['key' => $staff['key'], 'label' => $staff['label']];
        }
        $extra = config('shopify.staff_order_report.extra_buckets');
        if (is_array($extra)) {
            foreach ($extra as $bucket) {
                if (! is_array($bucket)) {
                    continue;
                }
                $key = is_string($bucket['key'] ?? null) ? trim($bucket['key']) : '';
                $label = is_string($bucket['label'] ?? null) ? trim($bucket['label']) : '';
                if ($key !== '' && $label !== '') {
                    $columns[] = ['key' => $key, 'label' => $label];
                }
            }
        }
        $columns[] = ['key' => 'total', 'label' => 'Total'];

        return $columns;
    }

    /**
     * @return array<string, array{key: string, label: string}>
     */
    private function staffByUserIdMap(): array
    {
        $configured = config('shopify.staff_order_report.staff');
        if (! is_array($configured)) {
            return [];
        }

        $map = [];
        foreach ($configured as $userId => $staff) {
            if (! is_array($staff)) {
                continue;
            }
            $key = is_string($staff['key'] ?? null) ? trim($staff['key']) : '';
            $label = is_string($staff['label'] ?? null) ? trim($staff['label']) : '';
            if ($key === '' || $label === '') {
                continue;
            }
            $map[(string) $userId] = ['key' => $key, 'label' => $label];
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, array{key: string, label: string}>  $staffByUserId
     */
    private function resolveBucket(array $node, array $staffByUserId): string
    {
        $source = is_string($node['sourceName'] ?? null) ? $node['sourceName'] : '';
        $channel = is_array($node['channelInformation']['channelDefinition'] ?? null)
            ? ($node['channelInformation']['channelDefinition']['channelName'] ?? null)
            : null;
        $channelName = is_string($channel) ? $channel : null;

        $userId = null;
        if (strtolower(trim($source)) === 'pos') {
            $legacyId = is_string($node['legacyResourceId'] ?? null) ? $node['legacyResourceId'] : '';
            $userId = $this->userIdFetcher->fetchUserId($legacyId);
        }

        return $this->classifier->classify($source, $userId, $channelName, $staffByUserId);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function orderDay(array $node, string $timezone): ?string
    {
        $createdAt = is_string($node['createdAt'] ?? null) ? $node['createdAt'] : null;
        if ($createdAt === null || trim($createdAt) === '') {
            return null;
        }

        return CarbonImmutable::parse($createdAt)->timezone($timezone)->format('Y-m-d');
    }
}
