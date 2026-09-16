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

Schedule::command('store-preorders:close-expired')
    ->dailyAt('00:15')
    ->timezone('America/Toronto')
    ->name('store-preorders:close-expired-daily')
    ->withoutOverlapping(30);

Schedule::command('store-preorders:refresh-missing-photos')
    ->dailyAt('07:00')
    ->timezone('America/Toronto')
    ->name('store-preorders:refresh-missing-photos-daily')
    ->withoutOverlapping(120);

Schedule::command('storefront:model-kit-index-rebuild')
    ->dailyAt('03:45')
    ->timezone('America/Toronto')
    ->name('storefront:model-kit-index-rebuild-daily')
    ->withoutOverlapping(30);

Schedule::command('plamod:instock-sync')
    ->dailyAt('05:00')
    ->timezone('America/Toronto')
    ->name('plamod:instock-sync-daily')
    ->withoutOverlapping(180);

Schedule::command('plamod:preorders-sync')
    ->dailyAt('06:00')
    ->timezone('America/Toronto')
    ->name('plamod:preorders-sync-daily')
    ->withoutOverlapping(180);

if (config('database_backup.schedule.enabled', true)) {
    // Daily backup runs on host via pricing-tool-backup-and-offsite-push.sh when host_orchestrator=true.
    if (! config('database_backup.schedule.host_orchestrator', false)) {
        Schedule::command('db:backup --yes --description="Scheduled daily backup" --created-by=system')
            ->dailyAt((string) config('database_backup.schedule.backup_time', '02:30'))
            ->timezone('America/Toronto')
            ->name('db:backup-daily')
            ->withoutOverlapping(120);
    }

    Schedule::command('db:backup:purge --yes')
        ->weeklyOn(0, (string) config('database_backup.schedule.purge_time', '03:30'))
        ->timezone('America/Toronto')
        ->name('db:backup-purge-weekly')
        ->withoutOverlapping(30);
}
