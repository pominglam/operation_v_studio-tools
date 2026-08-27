<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;
use Illuminate\Console\Command;

final class ProductsPushModelKitTagsCommand extends Command
{
    protected $signature = 'products:push-model-kit-tags
        {--grade= : Limit to ERP grade (e.g. MG, MGEX, MGSD)}
        {--dry-run : List SKUs only}';

    protected $description = 'Push mk:* taxonomy tags from ERP to Shopify for model kits';

    public function handle(ShopifyProductPushBySkusService $shopifyPush): int
    {
        $gradeFilter = is_string($this->option('grade')) ? strtoupper(trim($this->option('grade'))) : '';

        $query = Product::query()
            ->where('main_type', 'model kit')
            ->orderBy('sku');

        if ($gradeFilter !== '') {
            $query->where('grade', $gradeFilter);
        }

        $skus = $query->pluck('sku')->map(static fn ($sku): string => (string) $sku)->all();

        $this->info('Model kits to push: '.count($skus));

        if ((bool) $this->option('dry-run')) {
            foreach (array_slice($skus, 0, 20) as $sku) {
                $this->line($sku);
            }
            if (count($skus) > 20) {
                $this->line('...');
            }

            return self::SUCCESS;
        }

        $rows = $shopifyPush->push($skus);
        $succeeded = count(array_filter($rows, static fn (array $row): bool => $row['action'] !== 'error'));
        $failed = count($rows) - $succeeded;

        $this->info("Shopify succeeded: {$succeeded}");
        $this->info("Shopify failed: {$failed}");

        foreach ($rows as $row) {
            if ($row['action'] === 'error') {
                $this->warn("FAIL {$row['sku']} | {$row['tags']}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
