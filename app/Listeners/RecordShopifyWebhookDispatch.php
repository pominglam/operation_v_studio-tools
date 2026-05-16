<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Shopify\ShopifyWebhookReceived;

/**
 * Phase 2: branch on {@see ShopifyWebhookReceived::$log}->topic for domain reactions (inventory deltas, orders, etc.).
 *
 * Kept directly under `App\Listeners` (not in a nested folder) so Laravel’s default event-discovery scan registers this class.
 */
final class RecordShopifyWebhookDispatch
{
    public function handle(ShopifyWebhookReceived $event): void
    {
        // Intentionally empty in Phase 1 — routing hook only.
        unset($event);
    }
}
