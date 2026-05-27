<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\DAL\Shopify\ShopifySyncStateRepository;
use App\Jobs\Shopify\ShopifyOrderReconcileJob;
use App\Services\Shopify\Admin\ShopifySettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class ShopifyOrderReconcileScheduler
{
    private const string CACHE_KEY_LAST_DISPATCH = 'shopify:orders_reconcile:last_dispatch';

    public function __construct(
        private readonly ShopifySettingsService $settings,
        private readonly ShopifySyncStateRepository $syncState,
    ) {}

    public function runIfDue(): bool
    {
        $intervalHours = $this->settings->getOrderReconcileIntervalHours();
        $state = $this->syncState->findByKey(ShopifySettingsService::SYNC_KEY_ORDERS);
        $anchor = $state?->last_success_at ?? $state?->last_run_started_at;

        if ($anchor !== null && $anchor->copy()->addHours($intervalHours)->isFuture()) {
            return false;
        }

        $lastDispatch = Cache::get(self::CACHE_KEY_LAST_DISPATCH);
        if (is_string($lastDispatch)) {
            $parsed = Carbon::parse($lastDispatch);
            if ($parsed->diffInMinutes(now()) < 1) {
                return false;
            }
        }

        Cache::put(self::CACHE_KEY_LAST_DISPATCH, now()->toISOString(), now()->addMinutes(2));
        ShopifyOrderReconcileJob::dispatch();

        return true;
    }
}
