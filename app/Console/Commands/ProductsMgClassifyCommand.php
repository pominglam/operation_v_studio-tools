<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Products\ProductMgClassificationCorrectionService;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;
use Illuminate\Console\Command;

final class ProductsMgClassifyCommand extends Command
{
    protected $signature = 'products:mg-classify
        {--apply : Persist corrections to ERP products}
        {--push-shopify : Push corrected SKUs to Shopify after apply}
        {--push-all-mg : Push every MG/MGEX/MGSD model kit to Shopify (even when ERP is already correct)}
        {--include-third-party : Include third-party MG-scale kits}';

    protected $description = 'Correct MG / Ver.Ka / MGEX / MGSD type, grade, and subline on model kits';

    public function handle(
        ProductMgClassificationCorrectionService $correction,
        ShopifyProductPushBySkusService $shopifyPush,
    ): int {
        $dryRun = ! (bool) $this->option('apply');
        $result = $correction->correct(
            dryRun: $dryRun,
            includeThirdParty: (bool) $this->option('include-third-party'),
        );

        $this->info('Scanned: '.$result['scanned']);
        $this->info(($dryRun ? 'Would update' : 'Updated').': '.$result['updated']);
        $this->info('Skipped: '.$result['skipped']);

        foreach ($result['changes'] as $change) {
            $this->line(sprintf(
                '%s | %s/%s/%s -> %s/%s/%s',
                $change['sku'],
                $change['before']['type'] ?? '-',
                $change['before']['grade'] ?? '-',
                $change['before']['subline'] ?? '-',
                $change['after']['type'],
                $change['after']['grade'],
                $change['after']['subline'] ?? '-',
            ));
        }

        if ($dryRun) {
            $this->comment('Dry run only. Re-run with --apply to persist.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('push-shopify') && $result['changes'] !== []) {
            if (! (bool) $this->option('apply')) {
                $this->error('--push-shopify requires --apply');

                return self::FAILURE;
            }

            $this->pushSkus($shopifyPush, array_map(
                static fn (array $change): string => $change['sku'],
                $result['changes'],
            ));
        }

        if ((bool) $this->option('push-all-mg')) {
            $skus = Product::query()
                ->where('main_type', 'model kit')
                ->whereIn('type', ['MG', 'MGEX', 'MGSD'])
                ->orderBy('sku')
                ->pluck('sku')
                ->map(static fn ($sku): string => (string) $sku)
                ->all();

            $this->info('Pushing all MG-family SKUs to Shopify: '.count($skus));
            $this->pushSkus($shopifyPush, $skus);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $skus
     */
    private function pushSkus(ShopifyProductPushBySkusService $shopifyPush, array $skus): void
    {
        if ($skus === []) {
            $this->info('No SKUs to push.');

            return;
        }

        $rows = $shopifyPush->push($skus);
        $succeeded = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if ($row['action'] === 'error') {
                $failed++;
                $this->warn("FAIL {$row['sku']} | {$row['tags']}");
            } else {
                $succeeded++;
            }
        }

        $this->info('Shopify push attempted: '.count($skus));
        $this->info('Shopify succeeded: '.$succeeded);
        $this->info('Shopify failed: '.$failed);
    }
}
