<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\DAL\Customers\ShopifyOrderIdentityRepository;
use App\Support\Customers\CustomerCadenceStatus;
use App\Support\Customers\CustomerChurnStatus;
use App\Support\Customers\CustomerFrequencyLabel;
use App\Support\Customers\CustomerRetentionSnapshot;
use App\Support\Customers\CustomerStoreCadenceCalculator;
use App\Support\Customers\ShopifyOrderIdentityRow;
use App\Support\Customers\ShopifyRfmGroup;
use App\Support\Customers\ShopMoney;

final class CustomerRetentionSnapshotBuilder
{
    public function __construct(
        private readonly ShopifyOrderIdentityRepository $orders,
        private readonly CustomerIdentityMergeService $merge,
        private readonly CustomerRfmScoringService $scoring,
    ) {}

    public function build(): CustomerRetentionSnapshot
    {
        $rows = $this->orders->eligibleIdentityRows();
        $merged = $this->merge->groupIdentifiedOrders($rows);
        $people = $this->scoring->score(
            $merged['groups'],
            $this->orders->customerDisplayNamesByGid($this->gidsFromGroups($merged['groups'])),
            $this->merge,
        );

        return new CustomerRetentionSnapshot(
            people: $people,
            eligibleOrders: count($rows),
            identifiedOrders: $merged['identified_orders'],
            unidentifiedOrders: $merged['unidentified_orders'],
            identifiedSpend: $merged['identified_spend'],
            unidentifiedSpend: $merged['unidentified_spend'],
            currency: 'CAD',
            rfmCounts: $this->rfmCounts($people),
            newCount: $this->countByFrequency($people, CustomerFrequencyLabel::NEW),
            repeatCount: $this->repeatHeadline($people),
            loyalCount: $this->countByFrequency($people, CustomerFrequencyLabel::LOYAL),
            churnedCount: $this->churnedCount($people),
            identifiedAov: ShopMoney::divide($merged['identified_spend'], $merged['identified_orders']),
            storeCadence: CustomerStoreCadenceCalculator::fromPeople($people),
            cadenceOnCount: $this->cadenceCount($people, CustomerCadenceStatus::ON_CADENCE),
            cadenceDueCount: $this->cadenceCount($people, CustomerCadenceStatus::DUE),
            cadenceLapsedCount: $this->cadenceCount($people, CustomerCadenceStatus::LAPSED),
        );
    }

    /**
     * @param  array<string, list<ShopifyOrderIdentityRow>>  $groups
     * @return list<string>
     */
    private function gidsFromGroups(array $groups): array
    {
        $gids = [];
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                if (is_string($row->customerGid) && $row->customerGid !== '') {
                    $gids[] = $row->customerGid;
                }
            }
        }

        return array_values(array_unique($gids));
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     * @return array<string, int>
     */
    private function rfmCounts(array $people): array
    {
        $counts = array_fill_keys(ShopifyRfmGroup::keys(), 0);
        foreach ($people as $person) {
            $counts[$person->rfmGroup] = ($counts[$person->rfmGroup] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     */
    private function countByFrequency(array $people, string $label): int
    {
        $count = 0;
        foreach ($people as $person) {
            if ($person->frequencyLabel === $label) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     */
    private function repeatHeadline(array $people): int
    {
        $count = 0;
        foreach ($people as $person) {
            if ($person->isRepeat) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     */
    private function churnedCount(array $people): int
    {
        $count = 0;
        foreach ($people as $person) {
            if ($person->churn?->status === CustomerChurnStatus::CHURNED) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<\App\Support\Customers\CustomerRetentionPerson>  $people
     */
    private function cadenceCount(array $people, string $status): int
    {
        $count = 0;
        foreach ($people as $person) {
            if ($person->cadence?->status === $status) {
                $count++;
            }
        }

        return $count;
    }
}
