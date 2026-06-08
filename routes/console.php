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

Schedule::command('plamod:preorders-sync')
    ->dailyAt('06:00')
    ->timezone('America/Toronto')
    ->name('plamod:preorders-sync-daily')
    ->withoutOverlapping(120);
