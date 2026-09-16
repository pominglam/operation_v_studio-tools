<?php

declare(strict_types=1);

namespace App\Support\Customers;

final readonly class CustomerStoreCadence
{
    public function __construct(
        public int $medianGapDays,
        public int $dueAfterDays,
        public int $lapsedAfterDays,
        public int $typicalGapCount,
        public int $gapFloorDays,
    ) {}

    /**
     * @return array{
     *     median_gap_days: int,
     *     due_after_days: int,
     *     lapsed_after_days: int,
     *     typical_gap_count: int,
     *     gap_floor_days: int
     * }
     */
    public function toArray(): array
    {
        return [
            'median_gap_days' => $this->medianGapDays,
            'due_after_days' => $this->dueAfterDays,
            'lapsed_after_days' => $this->lapsedAfterDays,
            'typical_gap_count' => $this->typicalGapCount,
            'gap_floor_days' => $this->gapFloorDays,
        ];
    }
}
