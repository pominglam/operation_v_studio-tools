<?php

declare(strict_types=1);

namespace App\Support\Customers;

final readonly class CustomerChurnAssessment
{
    public function __construct(
        public string $status,
        public int $avgGapDays,
        public int $daysSinceLast,
        public int $thresholdDays,
    ) {}

    /**
     * @return array{status: string, avg_gap_days: int, days_since_last: int, threshold_days: int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'avg_gap_days' => $this->avgGapDays,
            'days_since_last' => $this->daysSinceLast,
            'threshold_days' => $this->thresholdDays,
        ];
    }
}
