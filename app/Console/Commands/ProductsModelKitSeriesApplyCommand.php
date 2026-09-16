<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DAL\Products\ProductRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Models\Product;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ProductsModelKitSeriesApplyCommand extends Command
{
    protected $signature = 'products:model-kit-series-apply
        {file=storage/app/model-kit-series-approved.json : JSON array of {sku, series, note?}}
        {--dry-run : Print changes without saving ERP or pushing Shopify}';

    protected $description = 'Apply operator-approved model-kit series patches from JSON, then push affected SKUs to Shopify';

    public function handle(ProductRepository $repo, ShopifyProductPushBySkusService $shopifyPush): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");
            $this->line('Run products:model-kit-series-audit first, copy model-kit-series-approved.template.json → model-kit-series-approved.json, edit, then apply.');

            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Could not read: {$path}");

            return self::FAILURE;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error('JSON must be an array of {sku, series, note?} objects');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        /** @var list<string> */
        $pushSkus = [];

        foreach ($decoded as $index => $entry) {
            if (! is_array($entry)) {
                $this->warn("Skipping index {$index}: not an object");

                continue;
            }

            $sku = isset($entry['sku']) ? trim((string) $entry['sku']) : '';
            $series = isset($entry['series']) ? trim((string) $entry['series']) : '';
            $note = isset($entry['note']) ? trim((string) $entry['note']) : '';

            if ($sku === '' || $series === '') {
                $this->warn("Skipping index {$index}: sku and series required");

                continue;
            }

            $product = Product::query()->where('sku', $sku)->first();
            if ($product === null) {
                $this->error("MISSING SKU: {$sku}");

                continue;
            }

            $before = trim((string) ($product->series ?? ''));
            $this->line("{$sku}: ".($note !== '' ? $note : 'series update'));
            $this->line('  series: '.($before !== '' ? $before : 'null')." → {$series}");

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($product, $series, $repo): void {
                $product->series = $series;
                $repo->save($product);
            });
            $pushSkus[] = $sku;
        }

        if ($dryRun) {
            $this->info('Dry run — no ERP or Shopify writes.');

            return self::SUCCESS;
        }

        if ($pushSkus === []) {
            $this->warn('No rows applied.');

            return self::SUCCESS;
        }

        $this->info('Pushing '.count($pushSkus).' SKU(s) to Shopify…');
        $rows = $shopifyPush->push($pushSkus, new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: false,
            price: false,
            publishStatus: false,
            salesChannels: false,
        ));
        $succeeded = count(array_filter($rows, static fn (array $row): bool => $row['action'] !== 'error'));
        $failed = count($rows) - $succeeded;
        $this->line("Success: {$succeeded}");
        $this->line("Failed: {$failed}");
        foreach ($rows as $row) {
            if ($row['action'] === 'error') {
                $this->error("  {$row['sku']}: {$row['tags']}");
            }
        }

        $this->info('Re-run: php artisan products:model-kit-series-audit');

        return self::SUCCESS;
    }
}
