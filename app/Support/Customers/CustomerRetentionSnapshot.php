<?php

declare(strict_types=1);

namespace App\Support\Customers;

final readonly class CustomerRetentionSnapshot
{
    /**
     * @param  list<CustomerRetentionPerson>  $people
     * @param  array<string, int>  $rfmCounts
     */
    public function __construct(
        public array $people,
        public int $eligibleOrders,
        public int $identifiedOrders,
        public int $unidentifiedOrders,
        public string $identifiedSpend,
        public string $unidentifiedSpend,
        public string $currency,
        public array $rfmCounts,
        public int $newCount,
        public int $repeatCount,
        public int $loyalCount,
        public int $churnedCount,
        public string $identifiedAov,
        public CustomerStoreCadence $storeCadence,
        public int $cadenceOnCount,
        public int $cadenceDueCount,
        public int $cadenceLapsedCount,
    ) {}

    public function personById(string $id): ?CustomerRetentionPerson
    {
        foreach ($this->people as $person) {
            if ($person->id === $id) {
                return $person;
            }
        }

        return null;
    }
}
