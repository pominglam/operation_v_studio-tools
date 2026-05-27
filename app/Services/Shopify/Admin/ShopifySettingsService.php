<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin;

use App\DAL\Maintenance\MaintenanceNoteRepository;

final class ShopifySettingsService
{
    public const string KEY_ORDER_RECONCILE_INTERVAL_HOURS = 'shopify_order_reconcile_interval_hours';

    public const string SYNC_KEY_ORDERS = 'orders';

    public function __construct(
        private readonly MaintenanceNoteRepository $notes,
        private readonly ShopifyOpsStatusService $opsStatus,
    ) {}

    public function getOrderReconcileIntervalHours(): int
    {
        $note = $this->notes->findByKey(self::KEY_ORDER_RECONCILE_INTERVAL_HOURS);
        $raw = is_string($note?->body) ? trim($note->body) : '';
        if ($raw === '' || ! ctype_digit($raw)) {
            return 12;
        }

        return max(1, min(168, (int) $raw));
    }

    public function setOrderReconcileIntervalHours(int $hours): int
    {
        $hours = max(1, min(168, $hours));
        $this->notes->upsert(self::KEY_ORDER_RECONCILE_INTERVAL_HOURS, (string) $hours);

        return $hours;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->opsStatus->snapshot();
    }
}
