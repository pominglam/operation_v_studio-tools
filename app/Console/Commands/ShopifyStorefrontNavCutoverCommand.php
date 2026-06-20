<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Write\ShopifyStorefrontNavCutoverService;
use App\Services\Shopify\Admin\Write\ShopifyStorefrontPilotCollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ShopifyStorefrontNavCutoverCommand extends Command
{
    protected $signature = 'shopify:storefront-nav-cutover
        {--export-only : Export main-menu rollback JSON only}
        {--skip-collections : Skip collection publish/upsert}
        {--skip-menu : Skip main-menu update}
        {--dry-run : Print planned actions without Shopify writes}';

    protected $description = 'Phase 9: publish tools & supplies collections and cut over main-menu to Tools & Supplies dropdown.';

    public function handle(
        ShopifyStorefrontPilotCollectionService $collections,
        ShopifyStorefrontNavCutoverService $nav,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $exportOnly = (bool) $this->option('export-only');
        $skipCollections = (bool) $this->option('skip-collections');
        $skipMenu = (bool) $this->option('skip-menu');

        if ($exportOnly) {
            if ($dryRun) {
                $this->warn('Dry-run: would export main-menu rollback JSON.');

                return self::SUCCESS;
            }

            $path = $nav->exportMainMenuRollback();
            $this->info('Exported main-menu rollback: '.$path);

            return self::SUCCESS;
        }

        if (! $skipCollections) {
            if ($dryRun) {
                $this->line('Dry-run: would upsert/publish all enabled department collections + hub.');
            } else {
                $departmentRows = $collections->ensureAllEnabledDepartmentCollections();
                $hubRow = $collections->ensureToolsAndSuppliesHubCollection();
                $this->info('Collections published:');
                $this->table(
                    ['Handle', 'Title', 'Products', 'URL'],
                    array_map(static fn (array $row): array => [
                        $row['handle'],
                        $row['title'],
                        (string) $row['product_count'],
                        $row['url'],
                    ], array_merge(array_values($departmentRows), [$hubRow])),
                );
            }
        }

        if ($dryRun) {
            $this->warn('Dry-run: would export main-menu rollback and apply Tools & Supplies cutover.');

            return self::SUCCESS;
        }

        if ($skipMenu) {
            $this->warn('Skipped main-menu export/update (--skip-menu).');

            return self::SUCCESS;
        }

        $rollbackPath = $nav->exportMainMenuRollback();
        $this->info('Rollback exported: '.$rollbackPath);

        $collectionGids = $nav->resolveCollectionGidsByHandle();
        $nav->applyMainMenuCutover($collectionGids);
        $this->info('main-menu updated: Tools & Supplies dropdown live.');

        $failed = false;
        foreach (ShopifyStorefrontNavCutoverService::TOOLS_SUPPLIES_CHILDREN as $child) {
            $url = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/')
                .'/collections/'.$child['handle'];
            $status = $this->verifyUrl($url);
            $this->line(sprintf('- %s → %s (%s)', $child['title'], $url, $status));
            if (! str_starts_with($status, '200')) {
                $failed = true;
            }
        }

        if ($failed) {
            $this->error('One or more collection URLs did not return HTTP 200.');

            return self::FAILURE;
        }

        $this->info('Phase 9 nav cutover complete. Smoke-test desktop + mobile nav in browser.');

        return self::SUCCESS;
    }

    private function verifyUrl(string $url): string
    {
        try {
            return (string) Http::timeout(20)->get($url)->status();
        } catch (\Throwable $e) {
            return 'error: '.$e->getMessage();
        }
    }
}
