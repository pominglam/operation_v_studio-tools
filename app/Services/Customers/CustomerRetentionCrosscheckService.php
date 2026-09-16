<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Support\Customers\CustomerCadenceStatus;
use App\Support\Customers\CustomerChurnCalculator;
use App\Support\Customers\CustomerChurnStatus;
use App\Support\Customers\CustomerFrequencyLabel;
use App\Support\Customers\CustomerPhoneNormalizer;
use App\Support\Customers\CustomerRetentionIndexFilters;
use App\Support\Customers\CustomerRetentionSnapshot;
use App\Support\Customers\CustomerRetentionTimezone;
use App\Support\Customers\CustomerStoreCadenceCalculator;
use App\Support\Customers\ShopifyRfmGroup;
use App\Support\Customers\ShopMoney;
use Carbon\CarbonImmutable;

final class CustomerRetentionCrosscheckService
{
    public function __construct(
        private readonly CustomerRetentionSnapshotBuilder $builder,
        private readonly CustomerRetentionReportService $report,
    ) {}

    /**
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, detail: string}>}
     */
    public function runInternal(): array
    {
        $snapshot = $this->builder->build();
        $summary = $this->report->paginate(new CustomerRetentionIndexFilters(
            search: null,
            frequency: null,
            rfmGroup: null,
            churn: null,
            cadence: null,
            sortBy: 'last_order_at',
            sortDir: 'desc',
            page: 1,
            perPage: 1,
        ))['summary'];
        $checks = [
            $this->check('eligible_splits', $snapshot->eligibleOrders === $snapshot->identifiedOrders + $snapshot->unidentifiedOrders, sprintf(
                'eligible %d = identified %d + unidentified %d',
                $snapshot->eligibleOrders,
                $snapshot->identifiedOrders,
                $snapshot->unidentifiedOrders,
            )),
            $this->check('order_count_sum', $this->sumOrderCounts($snapshot) === $snapshot->identifiedOrders, sprintf(
                'sum(person orders) %d = identified %d',
                $this->sumOrderCounts($snapshot),
                $snapshot->identifiedOrders,
            )),
            $this->check('spend_sum', ShopMoney::compare($this->sumSpend($snapshot), $snapshot->identifiedSpend) === 0, sprintf(
                'sum(person spend) %s = identified %s',
                $this->sumSpend($snapshot),
                $snapshot->identifiedSpend,
            )),
            $this->check('frequency_partition', $this->frequencyPartitionOk($snapshot), sprintf(
                'new %d + exactly-2 %d + loyal %d = people %d; repeat headline %d',
                $snapshot->newCount,
                $this->exactlyTwo($snapshot),
                $snapshot->loyalCount,
                count($snapshot->people),
                $snapshot->repeatCount,
            )),
            $this->check('rfm_partition', array_sum($snapshot->rfmCounts) === count($snapshot->people), sprintf(
                'rfm groups %d = people %d',
                array_sum($snapshot->rfmCounts),
                count($snapshot->people),
            )),
            $this->check('labels_match_counts', $this->labelsMatchCounts($snapshot), 'each person New/Repeat/Loyal matches order count'),
            $this->check('rfm_matches_scores', $this->rfmMatchesScores($snapshot), 'each person RFM group matches R/F/M'),
            $this->check('no_pii_in_names', $this->noPiiInNames($snapshot), 'display names are not emails or phone numbers'),
            $this->check('api_summary_matches', $this->apiSummaryMatches($snapshot, $summary), 'report API summary matches snapshot'),
            $this->check('monthly_timeline', $this->monthlyTimelineOk($snapshot, $summary), 'month New + orders + spend reconcile'),
            $this->check('aov_and_churn', $this->aovAndChurnOk($snapshot, $summary), 'AOV = spend/orders; churn is 2× own average gap'),
            $this->check('store_cadence', $this->storeCadenceOk($snapshot, $summary), 'cadence vs store median of 14+ day gaps; lapsed = max(60, 2× median)'),
        ];

        return [
            'ok' => $this->allOk($checks),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }

    private function sumOrderCounts(CustomerRetentionSnapshot $snapshot): int
    {
        $sum = 0;
        foreach ($snapshot->people as $person) {
            $sum += $person->orderCount;
        }

        return $sum;
    }

    private function sumSpend(CustomerRetentionSnapshot $snapshot): string
    {
        $sum = '0.00';
        foreach ($snapshot->people as $person) {
            $sum = ShopMoney::add($sum, $person->spend);
        }

        return $sum;
    }

    private function exactlyTwo(CustomerRetentionSnapshot $snapshot): int
    {
        $count = 0;
        foreach ($snapshot->people as $person) {
            if ($person->frequencyLabel === CustomerFrequencyLabel::REPEAT) {
                $count++;
            }
        }

        return $count;
    }

    private function frequencyPartitionOk(CustomerRetentionSnapshot $snapshot): bool
    {
        $people = count($snapshot->people);

        return $snapshot->newCount + $this->exactlyTwo($snapshot) + $snapshot->loyalCount === $people
            && $snapshot->repeatCount === $people - $snapshot->newCount;
    }

    private function labelsMatchCounts(CustomerRetentionSnapshot $snapshot): bool
    {
        foreach ($snapshot->people as $person) {
            if ($person->frequencyLabel !== CustomerFrequencyLabel::fromOrderCount($person->orderCount)) {
                return false;
            }
            if ($person->isRepeat !== CustomerFrequencyLabel::isRepeat($person->orderCount)) {
                return false;
            }
            if ($person->orderCount !== count($person->orders)) {
                return false;
            }
        }

        return true;
    }

    private function rfmMatchesScores(CustomerRetentionSnapshot $snapshot): bool
    {
        foreach ($snapshot->people as $person) {
            if ($person->fmScore !== intdiv($person->frequencyScore + $person->monetaryScore, 2)) {
                return false;
            }
            if ($person->rfmGroup !== ShopifyRfmGroup::fromScores(
                $person->recencyScore,
                $person->frequencyScore,
                $person->monetaryScore,
            )) {
                return false;
            }
        }

        return true;
    }

    private function noPiiInNames(CustomerRetentionSnapshot $snapshot): bool
    {
        foreach ($snapshot->people as $person) {
            if (str_contains($person->displayName, '@')) {
                return false;
            }
            if (CustomerPhoneNormalizer::digits($person->displayName) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function apiSummaryMatches(CustomerRetentionSnapshot $snapshot, array $summary): bool
    {
        return (int) $summary['people'] === count($snapshot->people)
            && (int) $summary['identified_orders'] === $snapshot->identifiedOrders
            && (int) $summary['new_count'] === $snapshot->newCount
            && (int) $summary['repeat_count'] === $snapshot->repeatCount
            && (int) $summary['loyal_count'] === $snapshot->loyalCount
            && (int) $summary['churned_count'] === $snapshot->churnedCount
            && (int) $summary['cadence_on_count'] === $snapshot->cadenceOnCount
            && (int) $summary['cadence_due_count'] === $snapshot->cadenceDueCount
            && (int) $summary['cadence_lapsed_count'] === $snapshot->cadenceLapsedCount
            && ShopMoney::compare((string) $summary['identified_aov'], $snapshot->identifiedAov) === 0;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function aovAndChurnOk(CustomerRetentionSnapshot $snapshot, array $summary): bool
    {
        if ((int) $summary['churned_count'] !== $snapshot->churnedCount) {
            return false;
        }
        $now = CarbonImmutable::now(CustomerRetentionTimezone::NAME);
        $churned = 0;
        foreach ($snapshot->people as $person) {
            if (ShopMoney::compare($person->aov, ShopMoney::divide($person->spend, $person->orderCount)) !== 0) {
                return false;
            }
            $fresh = CustomerChurnCalculator::fromOrders($person->orders, $now);
            if (($person->churn?->status ?? null) !== ($fresh?->status ?? null)) {
                return false;
            }
            if ($person->churn === null) {
                continue;
            }
            if ($person->churn->thresholdDays !== 2 * $person->churn->avgGapDays) {
                return false;
            }
            $expect = $person->churn->daysSinceLast > $person->churn->thresholdDays
                ? CustomerChurnStatus::CHURNED
                : CustomerChurnStatus::ACTIVE;
            if ($person->churn->status !== $expect) {
                return false;
            }
            if ($person->churn->status === CustomerChurnStatus::CHURNED) {
                $churned++;
            }
        }

        return $churned === $snapshot->churnedCount;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function storeCadenceOk(CustomerRetentionSnapshot $snapshot, array $summary): bool
    {
        $rhythm = CustomerStoreCadenceCalculator::fromPeople($snapshot->people);
        if ($rhythm->lapsedAfterDays !== max(60, 2 * $rhythm->medianGapDays)) {
            return false;
        }
        if ($rhythm->dueAfterDays !== $rhythm->medianGapDays) {
            return false;
        }
        if ($snapshot->storeCadence->lapsedAfterDays !== $rhythm->lapsedAfterDays) {
            return false;
        }
        $now = CarbonImmutable::now(CustomerRetentionTimezone::NAME);
        $counts = [
            CustomerCadenceStatus::ON_CADENCE => 0,
            CustomerCadenceStatus::DUE => 0,
            CustomerCadenceStatus::LAPSED => 0,
        ];
        foreach ($snapshot->people as $person) {
            $fresh = CustomerStoreCadenceCalculator::assess($person->orders, $now, $rhythm);
            if (($person->cadence?->status ?? null) !== ($fresh?->status ?? null)) {
                return false;
            }
            if ($person->cadence === null) {
                continue;
            }
            $counts[$person->cadence->status] = ($counts[$person->cadence->status] ?? 0) + 1;
        }

        return $counts[CustomerCadenceStatus::ON_CADENCE] === $snapshot->cadenceOnCount
            && $counts[CustomerCadenceStatus::DUE] === $snapshot->cadenceDueCount
            && $counts[CustomerCadenceStatus::LAPSED] === $snapshot->cadenceLapsedCount
            && (int) $summary['cadence_lapsed_count'] === $snapshot->cadenceLapsedCount;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function monthlyTimelineOk(CustomerRetentionSnapshot $snapshot, array $summary): bool
    {
        $months = $summary['months'] ?? null;
        if (! is_array($months)) {
            return false;
        }

        $acquired = 0;
        $orders = 0;
        $spend = '0.00';
        $nowRepeat = 0;
        foreach ($months as $row) {
            if (! is_array($row)) {
                return false;
            }
            $acquired += (int) $row['acquired'];
            $orders += (int) $row['orders'];
            $spend = ShopMoney::add($spend, (string) $row['spend']);
            $nowRepeat += (int) $row['acquired_now_repeat'];
            if ((int) $row['buyers'] !== (int) $row['acquired'] + (int) $row['returning_buyers']) {
                return false;
            }
            if (ShopMoney::compare((string) $row['aov'], ShopMoney::divide((string) $row['spend'], (int) $row['orders'])) !== 0) {
                return false;
            }
            $split = ShopMoney::add((string) ($row['acquired_spend'] ?? '0.00'), (string) ($row['returning_spend'] ?? '0.00'));
            if (ShopMoney::compare($split, (string) $row['spend']) !== 0) {
                return false;
            }
        }

        [$datedPeople, $datedOrders, $datedSpend, $datedRepeat] = $this->datedTotals($snapshot);

        return $acquired === $datedPeople
            && $orders === $datedOrders
            && ShopMoney::compare($spend, $datedSpend) === 0
            && $nowRepeat === $datedRepeat;
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: int}
     */
    private function datedTotals(CustomerRetentionSnapshot $snapshot): array
    {
        $people = 0;
        $orders = 0;
        $spend = '0.00';
        $repeat = 0;
        foreach ($snapshot->people as $person) {
            $hasDated = false;
            foreach ($person->orders as $order) {
                if ($order->orderedAtIso === null || trim($order->orderedAtIso) === '') {
                    continue;
                }
                $hasDated = true;
                $orders++;
                $spend = ShopMoney::add($spend, $order->subtotal ?? '0.00');
            }
            if (! $hasDated) {
                continue;
            }
            $people++;
            if ($person->isRepeat) {
                $repeat++;
            }
        }

        return [$people, $orders, $spend, $repeat];
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
