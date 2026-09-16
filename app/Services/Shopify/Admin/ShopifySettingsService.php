<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin;

use App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileIntervalService;

final class ShopifySettingsService
{
    public const string KEY_ORDER_RECONCILE_INTERVAL_HOURS = 'shopify_order_reconcile_interval_hours';

    public const string SYNC_KEY_ORDERS = 'orders';

    public function __construct(
        private readonly ShopifyOpsStatusService $opsStatus,
        private readonly ShopifyOrderReconcileIntervalService $interval,
    ) {}

    public function getOrderReconcileIntervalMinutes(): int
    {
        return $this->interval->getMinutes();
    }

    public function setOrderReconcileIntervalMinutes(int $minutes): int
    {
        return $this->interval->setMinutes($minutes);
    }

    public function getOrderReconcileIntervalHours(): int
    {
        return max(1, (int) ceil($this->interval->getMinutes() / 60));
    }

    public function setOrderReconcileIntervalHours(int $hours): int
    {
        $this->interval->setHours($hours);

        return $this->getOrderReconcileIntervalHours();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->opsStatus->snapshot();
    }
}
