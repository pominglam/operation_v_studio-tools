<?php

declare(strict_types=1);

namespace App\Support\Customers;

final readonly class CustomerRetentionPerson
{
    /**
     * @param  list<CustomerRetentionOrderRef>  $orders
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $frequencyLabel,
        public bool $isRepeat,
        public string $rfmGroup,
        public string $rfmGroupName,
        public int $recencyScore,
        public int $frequencyScore,
        public int $monetaryScore,
        public int $fmScore,
        public int $orderCount,
        public string $spend,
        public string $aov,
        public ?CustomerCadenceAssessment $cadence,
        public ?CustomerChurnAssessment $churn,
        public ?string $lastOrderAtIso,
        public ?string $shopifyAdminUrl,
        public array $orders,
    ) {}
}
