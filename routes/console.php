<?php

use App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static function (): void {
    app(ShopifyOrderReconcileScheduler::class)->runIfDue();
})->everyMinute()->name('shopify:orders-reconcile-if-due')->withoutOverlapping(5);

// Temporarily disabled (2026-06-18): daily run was blocking the shared queue worker and sync is not stable yet.
// Manual trigger remains: POST /api/v1/preorders/sync or `php artisan plamod:preorders-sync`.
// Schedule::command('plamod:preorders-sync')
//     ->dailyAt('06:00')
//     ->timezone('America/Toronto')
//     ->name('plamod:preorders-sync-daily')
//     ->withoutOverlapping(120);
