<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Support\Customers\CustomerCadenceStatus;
use App\Support\Customers\CustomerChurnStatus;
use App\Support\Customers\CustomerFrequencyLabel;
use App\Support\Customers\CustomerRetentionIndexFilters;
use App\Support\Customers\CustomerRetentionPerson;
use App\Support\Customers\CustomerRetentionSnapshot;
use App\Support\Customers\ShopifyRfmCatalog;
use App\Support\Customers\ShopMoney;

final class CustomerRetentionReportService
{
    public function __construct(
        private readonly CustomerRetentionSnapshotBuilder $builder,
        private readonly CustomerRetentionMonthlySeriesBuilder $months,
    ) {}

    /**
     * @return array{people: list<CustomerRetentionPerson>, summary: array<string, mixed>, meta: array<string, mixed>}
     */
    public function paginate(CustomerRetentionIndexFilters $filters): array
    {
        $snapshot = $this->builder->build();
        $filtered = $this->filterPeople($snapshot->people, $filters);
        $this->sortPeople($filtered, $filters->sortBy, $filters->sortDir);

        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / $filters->perPage));
        $page = min($filters->page, $lastPage);
        $offset = ($page - 1) * $filters->perPage;

        return [
            'people' => array_slice($filtered, $offset, $filters->perPage),
            'summary' => $this->summary($snapshot, $total),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $filters->perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array{person: CustomerRetentionPerson, orders: list<array<string, mixed>>}|null
     */
    public function detail(string $personId): ?array
    {
        $person = $this->builder->build()->personById($personId);
        if ($person === null) {
            return null;
        }

        return [
            'person' => $person,
            'orders' => array_map(
                static fn ($order): array => $order->toArray(),
                $person->orders,
            ),
        ];
    }

    /**
     * @param  list<CustomerRetentionPerson>  $people
     * @return list<CustomerRetentionPerson>
     */
    private function filterPeople(array $people, CustomerRetentionIndexFilters $filters): array
    {
        $search = $filters->search !== null ? mb_strtolower($filters->search) : null;
        $out = [];
        foreach ($people as $person) {
            if (! $this->matchesFrequency($person, $filters->frequency)) {
                continue;
            }
            if ($filters->rfmGroup !== null && $person->rfmGroup !== $filters->rfmGroup) {
                continue;
            }
            if ($filters->churn !== null && ($person->churn?->status ?? '') !== $filters->churn) {
                continue;
            }
            if ($filters->cadence !== null && ($person->cadence?->status ?? '') !== $filters->cadence) {
                continue;
            }
            if ($search !== null && ! str_contains(mb_strtolower($person->displayName), $search)) {
                continue;
            }
            $out[] = $person;
        }

        return $out;
    }

    private function matchesFrequency(CustomerRetentionPerson $person, ?string $frequency): bool
    {
        if ($frequency === null) {
            return true;
        }
        if ($frequency === CustomerFrequencyLabel::REPEAT) {
            return $person->isRepeat;
        }

        return $person->frequencyLabel === $frequency;
    }

    /**
     * @param  list<CustomerRetentionPerson>  $people
     */
    private function sortPeople(array &$people, string $sortBy, string $dir): void
    {
        $rfmOrder = array_flip(array_column(ShopifyRfmCatalog::groups(), 'key'));
        $freqOrder = [
            CustomerFrequencyLabel::NEW => 1,
            CustomerFrequencyLabel::REPEAT => 2,
            CustomerFrequencyLabel::LOYAL => 3,
        ];
        usort($people, function (CustomerRetentionPerson $left, CustomerRetentionPerson $right) use ($sortBy, $dir, $rfmOrder, $freqOrder): int {
            $cmp = $this->comparePeople($left, $right, $sortBy, $rfmOrder, $freqOrder);

            return $dir === 'asc' ? $cmp : -$cmp;
        });
    }

    /**
     * @param  array<string, int>  $rfmOrder
     * @param  array<string, int>  $freqOrder
     */
    private function comparePeople(
        CustomerRetentionPerson $left,
        CustomerRetentionPerson $right,
        string $sortBy,
        array $rfmOrder,
        array $freqOrder,
    ): int {
        return match ($sortBy) {
            'display_name' => strcasecmp($left->displayName, $right->displayName),
            'frequency_label' => ($freqOrder[$left->frequencyLabel] ?? 0) <=> ($freqOrder[$right->frequencyLabel] ?? 0),
            'rfm_group' => ($rfmOrder[$left->rfmGroup] ?? 99) <=> ($rfmOrder[$right->rfmGroup] ?? 99),
            'order_count' => $left->orderCount <=> $right->orderCount,
            'spend' => ShopMoney::compare($left->spend, $right->spend),
            'aov' => ShopMoney::compare($left->aov, $right->aov),
            'cadence_status' => $this->cadenceRank($left) <=> $this->cadenceRank($right),
            'churn_status' => $this->churnRank($left) <=> $this->churnRank($right),
            'recency_score' => $left->recencyScore <=> $right->recencyScore,
            'frequency_score' => $left->frequencyScore <=> $right->frequencyScore,
            'monetary_score' => $left->monetaryScore <=> $right->monetaryScore,
            default => strcmp((string) $left->lastOrderAtIso, (string) $right->lastOrderAtIso),
        };
    }

    private function cadenceRank(CustomerRetentionPerson $person): int
    {
        return match ($person->cadence?->status) {
            CustomerCadenceStatus::LAPSED => 3,
            CustomerCadenceStatus::DUE => 2,
            CustomerCadenceStatus::ON_CADENCE => 1,
            default => 0,
        };
    }

    private function churnRank(CustomerRetentionPerson $person): int
    {
        return match ($person->churn?->status) {
            CustomerChurnStatus::CHURNED => 2,
            CustomerChurnStatus::ACTIVE => 1,
            default => 0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(CustomerRetentionSnapshot $snapshot, int $filteredPeople): array
    {
        $people = count($snapshot->people);
        $repeatRate = $people > 0
            ? number_format($snapshot->repeatCount / $people, 4, '.', '')
            : '0.0000';

        return [
            'eligible_orders' => $snapshot->eligibleOrders,
            'identified_orders' => $snapshot->identifiedOrders,
            'unidentified_orders' => $snapshot->unidentifiedOrders,
            'identified_spend' => $snapshot->identifiedSpend,
            'unidentified_spend' => $snapshot->unidentifiedSpend,
            'people' => $people,
            'filtered_people' => $filteredPeople,
            'new_count' => $snapshot->newCount,
            'repeat_count' => $snapshot->repeatCount,
            'loyal_count' => $snapshot->loyalCount,
            'repeat_rate' => $repeatRate,
            'churned_count' => $snapshot->churnedCount,
            'identified_aov' => $snapshot->identifiedAov,
            'store_cadence' => $snapshot->storeCadence->toArray(),
            'cadence_on_count' => $snapshot->cadenceOnCount,
            'cadence_due_count' => $snapshot->cadenceDueCount,
            'cadence_lapsed_count' => $snapshot->cadenceLapsedCount,
            'rfm_counts' => $snapshot->rfmCounts,
            'rfm_catalog' => ShopifyRfmCatalog::groups(),
            'months' => $this->months->build($snapshot->people),
            'revenue_currency' => $snapshot->currency,
            'scoring_note' => 'RFM scores are store quintiles on merged people (R1–R3). FM = floor((F + M) / 2). Prospects omitted.',
        ];
    }
}
