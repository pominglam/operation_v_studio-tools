<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Support\Customers\CustomerEmailNormalizer;
use App\Support\Customers\CustomerIdentityKeys;
use App\Support\Customers\CustomerIdentityUnion;
use App\Support\Customers\CustomerRetentionOrderRef;
use App\Support\Customers\CustomerRetentionPersonId;
use App\Support\Customers\ShopifyOrderIdentityRow;
use App\Support\Customers\ShopMoney;
use App\Support\Shopify\ShopifyOrderAdminUrl;

final class CustomerIdentityMergeService
{
    /**
     * @param  list<ShopifyOrderIdentityRow>  $rows
     * @return array{
     *     groups: array<string, list<ShopifyOrderIdentityRow>>,
     *     identified_orders: int,
     *     unidentified_orders: int,
     *     identified_spend: string,
     *     unidentified_spend: string
     * }
     */
    public function groupIdentifiedOrders(array $rows): array
    {
        $denylist = CustomerEmailNormalizer::denylistFromConfig();
        $union = new CustomerIdentityUnion;
        $identified = [];
        $unidentified = 0;
        $identifiedSpend = '0.00';
        $unidentifiedSpend = '0.00';

        foreach ($rows as $row) {
            $keys = CustomerIdentityKeys::fromOrder(
                $row->customerGid,
                $row->customerEmail,
                $row->customerPhone,
                $denylist,
            );
            $amount = $row->subtotalShopAmount ?? '0.00';
            if ($keys === []) {
                $unidentified++;
                $unidentifiedSpend = ShopMoney::add($unidentifiedSpend, $amount);

                continue;
            }

            $union->unionAll($keys);
            $identified[] = ['row' => $row, 'keys' => $keys];
            $identifiedSpend = ShopMoney::add($identifiedSpend, $amount);
        }

        return [
            'groups' => $this->groupsFromUnion($union, $identified),
            'identified_orders' => count($identified),
            'unidentified_orders' => $unidentified,
            'identified_spend' => $identifiedSpend,
            'unidentified_spend' => $unidentifiedSpend,
        ];
    }

    /**
     * @param  list<ShopifyOrderIdentityRow>  $rows
     * @return list<CustomerRetentionOrderRef>
     */
    public function orderRefs(array $rows): array
    {
        $refs = [];
        foreach ($rows as $row) {
            $refs[] = new CustomerRetentionOrderRef(
                id: $row->id,
                name: $row->name,
                orderedAtIso: $row->orderedAt?->toISOString(),
                subtotal: $row->subtotalShopAmount,
                channelLabel: $row->channelName ?? $row->sourceName,
                shopifyAdminUrl: ShopifyOrderAdminUrl::forLegacyId($row->legacyNumericId),
            );
        }

        usort($refs, static function (CustomerRetentionOrderRef $left, CustomerRetentionOrderRef $right): int {
            return strcmp((string) $right->orderedAtIso, (string) $left->orderedAtIso);
        });

        return $refs;
    }

    /**
     * @param  list<array{row: ShopifyOrderIdentityRow, keys: list<string>}>  $identified
     * @return array<string, list<ShopifyOrderIdentityRow>>
     */
    private function groupsFromUnion(CustomerIdentityUnion $union, array $identified): array
    {
        $rootKeys = [];
        foreach ($identified as $item) {
            $root = $union->find($item['keys'][0]);
            $rootKeys[$root] ??= [];
            foreach ($item['keys'] as $key) {
                $rootKeys[$root][] = $key;
            }
        }

        $groups = [];
        foreach ($identified as $item) {
            $root = $union->find($item['keys'][0]);
            $personId = CustomerRetentionPersonId::fromKeys($rootKeys[$root] ?? $item['keys']);
            $groups[$personId] ??= [];
            $groups[$personId][] = $item['row'];
        }

        return $groups;
    }
}
