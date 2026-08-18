<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Orders\ShopifyStaffOrdersMonthlyReportService;
use Illuminate\Console\Command;

final class ShopifyOrderStaffAttributionBackfillCommand extends Command
{
    protected $signature = 'shopify:orders-backfill-staff-attribution
                            {from_month : Start calendar month as YYYY-MM}
                            {--to= : Inclusive end month YYYY-MM; defaults to from_month}';

    protected $description = 'Backfill staff attribution and subtotal columns on mirrored shopify_orders rows';

    public function handle(ShopifyStaffOrdersMonthlyReportService $reportService): int
    {
        $fromMonth = trim((string) $this->argument('from_month'));
        $toMonth = trim((string) ($this->option('to') ?: $fromMonth));

        if (! preg_match('/^\d{4}-\d{2}$/', $fromMonth) || ! preg_match('/^\d{4}-\d{2}$/', $toMonth)) {
            $this->error('Months must be YYYY-MM.');

            return self::FAILURE;
        }

        if ($fromMonth > $toMonth) {
            $this->error('End month must be on or after start month.');

            return self::FAILURE;
        }

        $summary = $reportService->backfillAttributionForRange($fromMonth, $toMonth);
        $this->info(sprintf(
            'Updated %d mirrored order row(s) across %d Shopify page(s) for %s through %s.',
            (int) ($summary['orders_updated'] ?? 0),
            (int) ($summary['pages'] ?? 0),
            $fromMonth,
            $toMonth,
        ));

        return self::SUCCESS;
    }
}
