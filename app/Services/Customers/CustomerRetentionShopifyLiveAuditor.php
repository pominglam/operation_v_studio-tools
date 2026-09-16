<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Shopify\ShopifyOrder;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Orders\ShopifyOrderDemandEligibility;
use App\Support\Customers\CustomerCadenceStatus;
use App\Support\Customers\CustomerChurnStatus;
use App\Support\Customers\CustomerEmailNormalizer;
use App\Support\Customers\CustomerIdentityKeys;
use App\Support\Customers\CustomerRetentionSnapshot;
use App\Support\Customers\CustomerStoreCadenceCalculator;
use App\Support\Customers\ShopifyOrderIdentityRow;
use App\Support\Customers\ShopMoney;
use App\Support\Shopify\Admin\Orders\ShopifyOrderGraphQlCustomerIdentity;
use App\Support\Shopify\Admin\Orders\ShopifyOrderGraphQlSubtotal;
use Carbon\CarbonImmutable;

final class CustomerRetentionShopifyLiveAuditor
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyOrderDemandEligibility $eligibility,
        private readonly CustomerIdentityMergeService $merge,
        private readonly CustomerRfmScoringService $scoring,
        private readonly CustomerRetentionSnapshotBuilder $builder,
    ) {}

    /**
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>, mismatches: list<string>}
     */
    public function run(): array
    {
        $liveRows = $this->fetchLiveRows();
        $erpByGid = ShopifyOrder::query()->get([
            'gid',
            'customer_gid',
            'customer_email',
            'customer_phone',
            'subtotal_shop_amount',
            'cancelled_at',
            'display_financial_status',
        ])->keyBy('gid');

        $mismatches = $this->rowMismatches($liveRows, $erpByGid);
        $liveEligible = array_values(array_filter(
            $liveRows,
            static fn (ShopifyOrderIdentityRow $row): bool => $row->eligible,
        ));
        $erp = $this->builder->build();
        $liveSnapshot = $this->snapshotFromRows($liveEligible);
        $missingInErp = $this->missingInErp($liveRows, $erpByGid);
        $extraInErp = max(0, $erpByGid->count() - (count($liveRows) - $missingInErp));

        $checks = [
            $this->check('live_order_count', $missingInErp === 0 && $extraInErp === 0, sprintf(
                'Shopify %d orders; ERP %d; missing in ERP %d; extra in ERP %d',
                count($liveRows),
                $erpByGid->count(),
                $missingInErp,
                $extraInErp,
            )),
            $this->check('identity_field_match', $mismatches === [], sprintf(
                '%d identity/eligibility/subtotal mismatches',
                count($mismatches),
            )),
            $this->check('identified_orders', $liveSnapshot->identifiedOrders === $erp->identifiedOrders, sprintf(
                'live identified %d vs ERP %d',
                $liveSnapshot->identifiedOrders,
                $erp->identifiedOrders,
            )),
            $this->check('people', count($liveSnapshot->people) === count($erp->people), sprintf(
                'live people %d vs ERP %d',
                count($liveSnapshot->people),
                count($erp->people),
            )),
            $this->check('new_repeat_loyal', $this->frequencyMatches($liveSnapshot, $erp), sprintf(
                'live new/repeat/loyal %d/%d/%d vs ERP %d/%d/%d',
                $liveSnapshot->newCount,
                $liveSnapshot->repeatCount,
                $liveSnapshot->loyalCount,
                $erp->newCount,
                $erp->repeatCount,
                $erp->loyalCount,
            )),
            $this->check('identified_spend', ShopMoney::compare($liveSnapshot->identifiedSpend, $erp->identifiedSpend) === 0, sprintf(
                'live spend %s vs ERP %s',
                $liveSnapshot->identifiedSpend,
                $erp->identifiedSpend,
            )),
        ];

        return [
            'ok' => $this->allOk($checks),
            'checks' => $checks,
            'mismatches' => array_slice($mismatches, 0, 25),
        ];
    }

    /**
     * @return list<ShopifyOrderIdentityRow>
     */
    private function fetchLiveRows(): array
    {
        $rows = [];
        $cursor = null;
        $pageSize = (int) config('shopify.graphql_page_size', 50);
        while (true) {
            $resp = $this->client->query(ShopifyAdminGraphQlQueries::ORDERS_PAGE, [
                'first' => $pageSize,
                'after' => $cursor,
            ]);
            $page = $resp['data']['orders'] ?? null;
            if (! is_array($page)) {
                throw new ShopifyGraphQlException('Shopify orders response missing data.orders.');
            }
            foreach ($page['nodes'] ?? [] as $node) {
                if (is_array($node)) {
                    $rows[] = $this->rowFromNode($node);
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

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function rowFromNode(array $node): ShopifyOrderIdentityRow
    {
        $identity = ShopifyOrderGraphQlCustomerIdentity::attributesFromGraphQlNode($node);
        $createdAt = isset($node['createdAt']) && is_string($node['createdAt'])
            ? CarbonImmutable::parse($node['createdAt'])
            : null;

        return new ShopifyOrderIdentityRow(
            id: 0,
            gid: is_string($node['id'] ?? null) ? $node['id'] : '',
            legacyNumericId: isset($node['legacyResourceId']) ? (string) $node['legacyResourceId'] : null,
            name: is_string($node['name'] ?? null) ? $node['name'] : null,
            customerGid: $identity['customer_gid'],
            customerEmail: $identity['customer_email'],
            customerPhone: $identity['customer_phone'],
            subtotalShopAmount: ShopifyOrderGraphQlSubtotal::subtotalShopAmount($node),
            orderedAt: $createdAt,
            sourceName: is_string($node['sourceName'] ?? null) ? $node['sourceName'] : null,
            channelName: null,
            eligible: $this->eligibility->isEligibleFromGraphQlNode($node),
        );
    }

    /**
     * @param  list<ShopifyOrderIdentityRow>  $liveRows
     * @param  \Illuminate\Support\Collection<string, ShopifyOrder>  $erpByGid
     * @return list<string>
     */
    private function rowMismatches(array $liveRows, $erpByGid): array
    {
        $denylist = CustomerEmailNormalizer::denylistFromConfig();
        $mismatches = [];
        foreach ($liveRows as $live) {
            $erp = $erpByGid->get($live->gid);
            if ($erp === null) {
                $mismatches[] = $live->name.' missing in ERP';

                continue;
            }
            $erpEligible = $this->eligibility->isEligibleOrder($erp);
            if ($erpEligible !== $live->eligible) {
                $mismatches[] = $live->name.' eligibility live='.($live->eligible ? '1' : '0').' erp='.($erpEligible ? '1' : '0');
            }
            $erpKeys = CustomerIdentityKeys::fromOrder(
                is_string($erp->customer_gid) ? $erp->customer_gid : null,
                is_string($erp->customer_email) ? $erp->customer_email : null,
                is_string($erp->customer_phone) ? $erp->customer_phone : null,
                $denylist,
            );
            $liveKeys = CustomerIdentityKeys::fromOrder(
                $live->customerGid,
                $live->customerEmail,
                $live->customerPhone,
                $denylist,
            );
            sort($erpKeys);
            sort($liveKeys);
            if ($erpKeys !== $liveKeys) {
                $mismatches[] = $live->name.' identity keys differ';
            }
            $erpSubtotal = $erp->subtotal_shop_amount !== null
                ? number_format((float) $erp->subtotal_shop_amount, 2, '.', '')
                : null;
            if ($erpSubtotal !== $live->subtotalShopAmount) {
                $mismatches[] = $live->name.' subtotal live='.$live->subtotalShopAmount.' erp='.$erpSubtotal;
            }
        }

        return $mismatches;
    }

    /**
     * @param  list<ShopifyOrderIdentityRow>  $rows
     */
    private function snapshotFromRows(array $rows): CustomerRetentionSnapshot
    {
        $merged = $this->merge->groupIdentifiedOrders($rows);
        $people = $this->scoring->score($merged['groups'], [], $this->merge);

        return new CustomerRetentionSnapshot(
            people: $people,
            eligibleOrders: count($rows),
            identifiedOrders: $merged['identified_orders'],
            unidentifiedOrders: $merged['unidentified_orders'],
            identifiedSpend: $merged['identified_spend'],
            unidentifiedSpend: $merged['unidentified_spend'],
            currency: 'CAD',
            rfmCounts: [],
            newCount: $this->countNew($people),
            repeatCount: $this->countRepeat($people),
            loyalCount: $this->countLoyal($people),
            churnedCount: $this->countStatus($people, static fn ($person): bool => $person->churn?->status === CustomerChurnStatus::CHURNED),
            identifiedAov: ShopMoney::divide($merged['identified_spend'], $merged['identified_orders']),
            storeCadence: CustomerStoreCadenceCalculator::fromPeople($people),
            cadenceOnCount: $this->countStatus($people, static fn ($person): bool => $person->cadence?->status === CustomerCadenceStatus::ON_CADENCE),
            cadenceDueCount: $this->countStatus($people, static fn ($person): bool => $person->cadence?->status === CustomerCadenceStatus::DUE),
            cadenceLapsedCount: $this->countStatus($people, static fn ($person): bool => $person->cadence?->status === CustomerCadenceStatus::LAPSED),
        );
    }

    /**
     * @param  list<ShopifyOrderIdentityRow>  $liveRows
     * @param  \Illuminate\Support\Collection<string, ShopifyOrder>  $erpByGid
     */
    private function missingInErp(array $liveRows, $erpByGid): int
    {
        $missing = 0;
        foreach ($liveRows as $row) {
            if (! $erpByGid->has($row->gid)) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     */
    private function countNew(array $people): int
    {
        return count(array_filter($people, static fn ($p): bool => $p->frequencyLabel === 'new'));
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     */
    private function countRepeat(array $people): int
    {
        return count(array_filter($people, static fn ($p): bool => $p->isRepeat));
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     */
    private function countLoyal(array $people): int
    {
        return count(array_filter($people, static fn ($p): bool => $p->frequencyLabel === 'loyal'));
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     * @param  callable(\App\Support\Customers\CustomerRetentionPerson): bool  $match
     */
    private function countStatus(array $people, callable $match): int
    {
        $count = 0;
        foreach ($people as $person) {
            if ($match($person)) {
                $count++;
            }
        }

        return $count;
    }

    private function frequencyMatches(CustomerRetentionSnapshot $live, CustomerRetentionSnapshot $erp): bool
    {
        return $live->newCount === $erp->newCount
            && $live->repeatCount === $erp->repeatCount
            && $live->loyalCount === $erp->loyalCount;
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * @param  list<array{ok: bool}>  $checks
     */
    private function allOk(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! $check['ok']) {
                return false;
            }
        }

        return true;
    }
}
