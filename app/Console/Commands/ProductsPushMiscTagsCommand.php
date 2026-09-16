<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;
use Illuminate\Console\Command;

final class ProductsPushMiscTagsCommand extends Command
{
    protected $signature = 'products:push-misc-tags
        {--dry-run : List SKUs only}';

    protected $description = 'Push misc:* taxonomy tags from ERP to Shopify for miscellaneous / figures products';

    public function handle(ShopifyProductPushBySkusService $shopifyPush): int
    {
        $skus = Product::query()
            ->where(function ($query): void {
                $query->where('main_type', 'misc')
                    ->orWhere('department', 'misc')
                    ->orWhere('department', 'figures');
            })
            ->orderBy('sku')
            ->pluck('sku')
            ->map(static fn ($sku): string => (string) $sku)
            ->all();

        $this->info('Misc / figures products to push: '.count($skus));

        if ((bool) $this->option('dry-run')) {
            foreach (array_slice($skus, 0, 30) as $sku) {
                $this->line($sku);
            }

            return self::SUCCESS;
        }

        $rows = $shopifyPush->push($skus);
        $succeeded = count(array_filter($rows, static fn (array $row): bool => $row['action'] !== 'error'));
        $failed = count($rows) - $succeeded;

        $this->info("Shopify succeeded: {$succeeded}");
        $this->info("Shopify failed: {$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
