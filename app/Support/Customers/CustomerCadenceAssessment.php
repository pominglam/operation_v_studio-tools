<?php

declare(strict_types=1);

namespace App\Support\Customers;

final readonly class CustomerCadenceAssessment
{
    public function __construct(
        public string $status,
        public int $daysSinceLast,
        public int $dueAfterDays,
        public int $lapsedAfterDays,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     days_since_last: int,
     *     due_after_days: int,
     *     lapsed_after_days: int
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'days_since_last' => $this->daysSinceLast,
            'due_after_days' => $this->dueAfterDays,
            'lapsed_after_days' => $this->lapsedAfterDays,
        ];
    }
}
