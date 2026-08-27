<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\ProductModelKitErpClassifyService;
use Illuminate\Console\Command;

final class ProductsModelKitErpClassifyCommand extends Command
{
    protected $signature = 'products:model-kit-classify-erp
        {--dry-run : Preview changes without saving}';

    protected $description = 'Fill model-kit ERP taxonomy fields from rules (no Shopify push)';

    public function handle(ProductModelKitErpClassifyService $classify): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $classify->classify($dryRun);

        $this->info('Scanned: '.$result['scanned']);
        $this->info(($dryRun ? 'Would update' : 'Updated').': '.$result['updated']);
        $this->info('Skipped: '.$result['skipped']);

        if ($dryRun) {
            $this->comment('Dry run. Re-run without --dry-run to persist.');
        }

        return self::SUCCESS;
    }
}
