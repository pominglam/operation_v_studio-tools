<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\DAL\Maintenance\MaintenanceNoteRepository;
use App\Services\Shopify\Admin\ShopifySettingsService;

final class ShopifyOrderReconcileIntervalService
{
    public const string KEY_MINUTES = 'shopify_order_reconcile_interval_minutes';

    public const int DEFAULT_MINUTES = 30;

    public const int MIN_MINUTES = 15;

    public const int MAX_MINUTES = 10_080;

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
    ) {}

    public function getMinutes(): int
    {
        $minutesNote = $this->notes->findByKey(self::KEY_MINUTES);
        $minutesRaw = is_string($minutesNote?->body) ? trim($minutesNote->body) : '';
        if ($minutesRaw !== '' && ctype_digit($minutesRaw)) {
            return $this->clampMinutes((int) $minutesRaw);
        }

        $hoursNote = $this->notes->findByKey(ShopifySettingsService::KEY_ORDER_RECONCILE_INTERVAL_HOURS);
        $hoursRaw = is_string($hoursNote?->body) ? trim($hoursNote->body) : '';
        if ($hoursRaw !== '' && ctype_digit($hoursRaw)) {
            return $this->clampMinutes(((int) $hoursRaw) * 60);
        }

        return self::DEFAULT_MINUTES;
    }

    public function setMinutes(int $minutes): int
    {
        $minutes = $this->clampMinutes($minutes);
        $this->notes->upsert(self::KEY_MINUTES, (string) $minutes);

        return $minutes;
    }

    public function setHours(int $hours): int
    {
        return $this->setMinutes(max(1, $hours) * 60);
    }

    private function clampMinutes(int $minutes): int
    {
        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes));
    }
}
