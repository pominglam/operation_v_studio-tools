<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Shopify\ShopifyOrder;
use App\Support\Shopify\Admin\Orders\ShopifyOrderGraphQlSubtotal;
use Carbon\CarbonImmutable;

final class ShopifyStaffOrdersMonthlyReportService
{
    private const string ORDERS_BACKFILL_QUERY = <<<'GQL'
query StaffOrdersAttributionBackfill($first: Int!, $after: String, $query: String) {
  orders(first: $first, after: $after, sortKey: CREATED_AT, reverse: false, query: $query) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      legacyResourceId
      createdAt
      sourceName
      cancelledAt
      displayFinancialStatus
      channelInformation { channelDefinition { channelName } }
      currentSubtotalPriceSet { shopMoney { amount currencyCode } }
    }
  }
}
GQL;

    public function __construct(
        private readonly ShopifyOrderStaffBucketClassifier $classifier,
        private readonly ShopifyOrderDemandEligibility $eligibility,
        private readonly ShopifyOrderStaffAttributionUpsertService $staffAttribution,
        private readonly ShopifyAdminGraphQlClientInterface $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reportForMonth(string $month): array
    {
        return $this->reportForRange(trim($month), trim($month));
    }

    /**
     * @return array<string, mixed>
     */
    public function reportForRange(string $fromMonth, string $toMonth): array
    {
        $fromMonth = trim($fromMonth);
        $toMonth = trim($toMonth);
        if ($fromMonth > $toMonth) {
            throw new \InvalidArgumentException('from_month must be on or before to_month.');
        }

        return $this->buildReport($fromMonth, $toMonth);
    }

    /**
     * @return array{orders_updated:int, pages:int}
     */
    public function backfillAttributionForMonth(string $month): array
    {
        return $this->backfillAttributionForRange(trim($month), trim($month));
    }

    /**
     * @return array{orders_updated:int, pages:int}
     */
    public function backfillAttributionForRange(string $fromMonth, string $toMonth): array
    {
        $fromMonth = trim($fromMonth);
        $toMonth = trim($toMonth);
        if ($fromMonth > $toMonth) {
            throw new \InvalidArgumentException('from_month must be on or before to_month.');
        }

        $ordersUpdated = 0;
        $pages = 0;
        for ($cursor = $fromMonth; $cursor <= $toMonth; $cursor = $this->shiftMonth($cursor, 1)) {
            $summary = $this->backfillAttributionForMonthInternal($cursor);
            $ordersUpdated += (int) ($summary['orders_updated'] ?? 0);
            $pages += (int) ($summary['pages'] ?? 0);
        }

        return [
            'orders_updated' => $ordersUpdated,
            'pages' => $pages,
        ];
    }

    /**
     * @return array{orders_updated:int, pages:int}
     */
    private function backfillAttributionForMonthInternal(string $month): array
    {
        $month = trim($month);
        $timezone = (string) config('shopify.staff_order_report.timezone', 'America/Toronto');
        [$rangeStart, $rangeEnd] = $this->monthRange($month, $timezone);
        $queryFilter = sprintf(
            'created_at:>=%s created_at:<%s',
            $rangeStart->format('Y-m-d'),
            $rangeEnd->format('Y-m-d'),
        );

        $ordersUpdated = 0;
        $pages = 0;
        $after = null;

        do {
            $response = $this->client->query(self::ORDERS_BACKFILL_QUERY, [
                'first' => 50,
                'after' => $after,
                'query' => $queryFilter,
            ]);
            $page = is_array($response['data']['orders'] ?? null) ? $response['data']['orders'] : [];
            $nodes = is_array($page['nodes'] ?? null) ? $page['nodes'] : [];
            $pages++;

            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $gid = is_string($node['id'] ?? null) ? trim($node['id']) : '';
                if ($gid === '') {
                    continue;
                }

                $attributes = $this->staffAttribution->attributesFromGraphQlNode($node);
                $attributes['subtotal_shop_amount'] = ShopifyOrderGraphQlSubtotal::subtotalShopAmount($node);
                $ordersUpdated += ShopifyOrder::query()->where('gid', $gid)->update($attributes);
            }

            $hasNext = ($page['pageInfo']['hasNextPage'] ?? false) === true;
            $after = $hasNext && is_string($page['pageInfo']['endCursor'] ?? null)
                ? $page['pageInfo']['endCursor']
                : null;
        } while ($after !== null);

        return [
            'orders_updated' => $ordersUpdated,
            'pages' => $pages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(string $fromMonth, string $toMonth): array
    {
        $timezone = (string) config('shopify.staff_order_report.timezone', 'America/Toronto');
        [$rangeStart, $rangeEnd, $daysInRange] = $this->dateRange($fromMonth, $toMonth, $timezone);
        $columns = $this->reportColumns();
        $bucketKeys = array_map(static fn (array $column): string => $column['key'], $columns);
        $bucketKeys = array_values(array_filter($bucketKeys, static fn (string $key): bool => $key !== 'date' && $key !== 'total'));

        $daily = [];
        $dailyRevenue = [];
        foreach ($daysInRange as $day) {
            $daily[$day] = array_fill_keys($bucketKeys, 0);
            $daily[$day]['total'] = 0;
            $dailyRevenue[$day] = array_fill_keys($bucketKeys, '0.00');
            $dailyRevenue[$day]['total'] = '0.00';
        }

        $ordersScanned = 0;
        $ordersMissingAttribution = 0;
        $ordersMissingSubtotal = 0;
        $staffByUserId = $this->staffByUserIdMap();

        $ordersQuery = ShopifyOrder::query();
        $this->eligibility->scopeDemandEligibleOrders($ordersQuery);
        $ordersQuery
            ->where('ordered_at_shop_tz', '>=', $rangeStart)
            ->where('ordered_at_shop_tz', '<', $rangeEnd)
            ->orderBy('ordered_at_shop_tz');

        foreach ($ordersQuery->cursor() as $order) {
            if (! $order instanceof ShopifyOrder) {
                continue;
            }

            $ordersScanned++;
            if (! is_string($order->source_name) || trim($order->source_name) === '') {
                $ordersMissingAttribution++;

                continue;
            }

            $bucket = $this->classifier->classify(
                $order->source_name,
                $order->pos_user_id !== null ? (int) $order->pos_user_id : null,
                is_string($order->channel_name) ? $order->channel_name : null,
                $staffByUserId,
            );
            $day = $this->orderDay($order, $timezone);
            if ($day === null || ! isset($daily[$day][$bucket])) {
                continue;
            }

            $daily[$day][$bucket]++;
            $daily[$day]['total']++;

            $subtotal = $this->orderSubtotalAmount($order);
            if ($subtotal === null) {
                $ordersMissingSubtotal++;

                continue;
            }

            $dailyRevenue[$day][$bucket] = $this->addMoney($dailyRevenue[$day][$bucket], $subtotal);
            $dailyRevenue[$day]['total'] = $this->addMoney($dailyRevenue[$day]['total'], $subtotal);
        }

        return $this->finalizeReport(
            $fromMonth,
            $toMonth,
            $timezone,
            $columns,
            $bucketKeys,
            $daysInRange,
            $daily,
            $dailyRevenue,
            $ordersScanned,
            $ordersMissingAttribution,
            $ordersMissingSubtotal,
        );
    }

    /**
     * @param  list<string>  $bucketKeys
     * @param  list<string>  $daysInRange
     * @param  array<string, array<string, int>>  $daily
     * @param  array<string, array<string, string>>  $dailyRevenue
     * @return array<string, mixed>
     */
    private function finalizeReport(
        string $fromMonth,
        string $toMonth,
        string $timezone,
        array $columns,
        array $bucketKeys,
        array $daysInRange,
        array $daily,
        array $dailyRevenue,
        int $ordersScanned,
        int $ordersMissingAttribution,
        int $ordersMissingSubtotal,
    ): array {
        $totals = array_fill_keys($bucketKeys, 0);
        $totals['total'] = 0;
        $revenueTotals = array_fill_keys($bucketKeys, '0.00');
        $revenueTotals['total'] = '0.00';
        foreach ($daily as $day => $counts) {
            foreach ($bucketKeys as $key) {
                $totals[$key] += (int) ($counts[$key] ?? 0);
            }
            $totals['total'] += (int) ($counts['total'] ?? 0);

            $dayRevenue = $dailyRevenue[$day] ?? [];
            foreach ($bucketKeys as $key) {
                $revenueTotals[$key] = $this->addMoney(
                    $revenueTotals[$key],
                    (string) ($dayRevenue[$key] ?? '0.00'),
                );
            }
            $revenueTotals['total'] = $this->addMoney(
                $revenueTotals['total'],
                (string) ($dayRevenue['total'] ?? '0.00'),
            );
        }

        $rows = [];
        $revenueRows = [];
        foreach ($daysInRange as $day) {
            $rows[] = array_merge(['date' => $day], $daily[$day]);
            $revenueRows[] = array_merge(['date' => $day], $dailyRevenue[$day]);
        }

        return [
            'from_month' => $fromMonth,
            'to_month' => $toMonth,
            'month' => $fromMonth === $toMonth ? $fromMonth : null,
            'timezone' => $timezone,
            'data_source' => 'shopify_orders_mirror',
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
            'revenue_rows' => $revenueRows,
            'revenue_totals' => $revenueTotals,
            'revenue_currency' => 'CAD',
            'orders_scanned' => $ordersScanned,
            'orders_missing_attribution' => $ordersMissingAttribution,
            'orders_missing_subtotal' => $ordersMissingSubtotal,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: list<string>}
     */
    private function dateRange(string $fromMonth, string $toMonth, string $timezone): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $fromMonth.'-01', $timezone)->startOfDay();
        if ($start === false) {
            throw new \InvalidArgumentException('Invalid from_month.');
        }

        $toStart = CarbonImmutable::createFromFormat('Y-m-d', $toMonth.'-01', $timezone)->startOfDay();
        if ($toStart === false) {
            throw new \InvalidArgumentException('Invalid to_month.');
        }

        $end = $toStart->addMonth();
        $days = [];
        for ($cursor = $start; $cursor->lt($end); $cursor = $cursor->addDay()) {
            $days[] = $cursor->format('Y-m-d');
        }

        return [$start, $end, $days];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: list<string>}
     */
    private function monthRange(string $month, string $timezone): array
    {
        return $this->dateRange($month, $month, $timezone);
    }

    private function shiftMonth(string $month, int $delta): string
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');
        if ($start === false) {
            throw new \InvalidArgumentException('Invalid month.');
        }

        return $start->addMonths($delta)->format('Y-m');
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

    private function orderDay(ShopifyOrder $order, string $timezone): ?string
    {
        $orderedAt = $order->ordered_at_shop_tz;
        if ($orderedAt === null) {
            return null;
        }

        return CarbonImmutable::parse($orderedAt)->timezone($timezone)->format('Y-m-d');
    }

    private function orderSubtotalAmount(ShopifyOrder $order): ?string
    {
        $amount = $order->subtotal_shop_amount;
        if ($amount === null) {
            return null;
        }

        $normalized = trim((string) $amount);
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function addMoney(string $left, string $right): string
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || ! is_numeric($left)) {
            $left = '0.00';
        }
        if ($right === '' || ! is_numeric($right)) {
            $right = '0.00';
        }

        return number_format((float) $left + (float) $right, 2, '.', '');
    }
}
