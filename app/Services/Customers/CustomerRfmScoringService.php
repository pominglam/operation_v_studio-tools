<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Support\Customers\CustomerChurnCalculator;
use App\Support\Customers\CustomerFrequencyLabel;
use App\Support\Customers\CustomerPhoneNormalizer;
use App\Support\Customers\CustomerRetentionPerson;
use App\Support\Customers\CustomerRetentionTimezone;
use App\Support\Customers\CustomerStoreCadenceCalculator;
use App\Support\Customers\ShopifyOrderIdentityRow;
use App\Support\Customers\ShopifyRfmCatalog;
use App\Support\Customers\ShopifyRfmGroup;
use App\Support\Customers\ShopifyRfmQuintileScorer;
use App\Support\Customers\ShopMoney;
use App\Support\Shopify\ShopifyCustomerAdminUrl;
use Carbon\CarbonImmutable;

final class CustomerRfmScoringService
{
    /**
     * @param  array<string, list<ShopifyOrderIdentityRow>>  $groups
     * @param  array<string, string>  $displayNames
     * @return list<CustomerRetentionPerson>
     */
    public function score(
        array $groups,
        array $displayNames,
        CustomerIdentityMergeService $orders,
    ): array {
        $stats = [];
        foreach ($groups as $personId => $rows) {
            $stats[] = $this->personStats($personId, $rows, $displayNames, $orders);
        }

        $recency = ShopifyRfmQuintileScorer::scoresDescending(array_column($stats, 'last_order_ts'));
        $frequency = ShopifyRfmQuintileScorer::scoresDescending(array_column($stats, 'order_count'));
        $monetary = ShopifyRfmQuintileScorer::scoresDescending(array_column($stats, 'spend'));

        $now = CarbonImmutable::now(CustomerRetentionTimezone::NAME);
        $rhythm = CustomerStoreCadenceCalculator::fromOrderSets(array_column($stats, 'orders'));
        $people = [];
        foreach ($stats as $index => $stat) {
            $r = $recency[$index];
            $f = $frequency[$index];
            $m = $monetary[$index];
            $group = ShopifyRfmGroup::fromScores($r, $f, $m);
            $people[] = new CustomerRetentionPerson(
                id: $stat['id'],
                displayName: $stat['display_name'],
                frequencyLabel: CustomerFrequencyLabel::fromOrderCount($stat['order_count']),
                isRepeat: CustomerFrequencyLabel::isRepeat($stat['order_count']),
                rfmGroup: $group,
                rfmGroupName: ShopifyRfmCatalog::nameFor($group),
                recencyScore: $r,
                frequencyScore: $f,
                monetaryScore: $m,
                fmScore: intdiv($f + $m, 2),
                orderCount: $stat['order_count'],
                spend: $stat['spend'],
                aov: ShopMoney::divide($stat['spend'], $stat['order_count']),
                cadence: CustomerStoreCadenceCalculator::assess($stat['orders'], $now, $rhythm),
                churn: CustomerChurnCalculator::fromOrders($stat['orders'], $now),
                lastOrderAtIso: $stat['last_order_iso'],
                shopifyAdminUrl: $stat['shopify_admin_url'],
                orders: $stat['orders'],
            );
        }

        return $people;
    }

    /**
     * @param  list<ShopifyOrderIdentityRow>  $rows
     * @param  array<string, string>  $displayNames
     * @return array{
     *     id: string,
     *     display_name: string,
     *     order_count: int,
     *     spend: string,
     *     last_order_ts: int,
     *     last_order_iso: ?string,
     *     shopify_admin_url: ?string,
     *     orders: list<\App\Support\Customers\CustomerRetentionOrderRef>
     * }
     */
    private function personStats(
        string $personId,
        array $rows,
        array $displayNames,
        CustomerIdentityMergeService $orders,
    ): array {
        $spend = '0.00';
        $latest = null;
        $latestGid = null;
        foreach ($rows as $row) {
            $spend = ShopMoney::add($spend, $row->subtotalShopAmount ?? '0.00');
            if ($latest === null || ($row->orderedAt !== null && $row->orderedAt->gt($latest))) {
                $latest = $row->orderedAt;
                $latestGid = $row->customerGid;
            }
        }

        $name = $latestGid !== null ? ($displayNames[$latestGid] ?? '') : '';

        return [
            'id' => $personId,
            'display_name' => $this->safeDisplayName($name),
            'order_count' => count($rows),
            'spend' => $spend,
            'last_order_ts' => $latest?->getTimestamp() ?? 0,
            'last_order_iso' => $latest?->toISOString(),
            'shopify_admin_url' => ShopifyCustomerAdminUrl::forCustomerGid($latestGid),
            'orders' => $orders->orderRefs($rows),
        ];
    }

    private function safeDisplayName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '' || str_contains($trimmed, '@') || CustomerPhoneNormalizer::digits($trimmed) !== null) {
            return 'Identified customer';
        }

        return $trimmed;
    }
}
