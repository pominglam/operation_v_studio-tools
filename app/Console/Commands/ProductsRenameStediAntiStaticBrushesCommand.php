<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Products\StediAntiStaticBrushRenameService;
use App\Services\Shopify\Admin\Write\ShopifyProductPushBySkusService;
use App\Support\Products\Storefront\StediAntiStaticBrushTitleResolver;
use Illuminate\Console\Command;

final class ProductsRenameStediAntiStaticBrushesCommand extends Command
{
    protected $signature = 'products:rename-stedi-anti-static-brushes
        {--apply : Actually update product names in the ERP database (default is dry-run)}
        {--push-shopify : Push renamed SKUs to Shopify after a successful ERP apply}
        {--preview=25 : Preview row limit}';

    protected $description = 'Renames Stedi MS-81/82/83 anti-static brushes to distinct titles, optionally pushing to Shopify.';

    public function handle(
        StediAntiStaticBrushRenameService $renameService,
        ShopifyProductPushBySkusService $pushService,
        StediAntiStaticBrushTitleResolver $titles,
    ): int {
        $apply = (bool) $this->option('apply');
        $pushShopify = (bool) $this->option('push-shopify');
        $preview = (int) $this->option('preview');
        if ($preview < 0) {
            $preview = 0;
        }

        if ($pushShopify && ! $apply) {
            $this->error('--push-shopify requires --apply (update ERP first, then push).');

            return self::FAILURE;
        }

        $this->info($apply ? 'Applying Stedi anti-static brush renames…' : 'Dry-run (no changes will be saved)…');

        $result = $renameService->rename($apply, $preview);

        $this->line('');
        $this->info("Matched: {$result['matched']}");
        $this->info(($apply ? 'Updated' : 'Would update').": {$result['changed']}");

        /** @var array<int, array{sku:string, old:string, new:string}> $rows */
        $rows = $result['preview'];
        if ($rows !== []) {
            $this->line('');
            $this->table(['SKU', 'Old name', 'New name'], array_map(static fn (array $r): array => [
                $r['sku'],
                $r['old'],
                $r['new'],
            ], $rows));
        }

        if ($pushShopify) {
            if ($result['changed'] === 0) {
                $this->warn('No ERP name changes — skipped Shopify push.');

                return self::SUCCESS;
            }

            $this->line('');
            $this->info('Pushing renamed SKUs to Shopify…');
            $pushRows = $pushService->push($titles->supportedSkus());
            $this->table(['SKU', 'Action', 'Shopify GID', 'Tags'], array_map(static fn (array $row): array => [
                $row['sku'],
                $row['action'],
                $row['shopify_gid'],
                $row['tags'],
            ], $pushRows));

            foreach ($pushRows as $row) {
                if (($row['action'] ?? '') === 'error' || ($row['tags'] ?? '') === 'VERIFY_FAILED') {
                    $this->error('Shopify push failed for one or more SKUs.');

                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }
}
