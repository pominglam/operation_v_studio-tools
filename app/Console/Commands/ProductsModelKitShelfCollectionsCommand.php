<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Write\ShopifyStorefrontPilotCollectionService;
use App\Support\Products\Storefront\ModelKitShelfCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ProductsModelKitShelfCollectionsCommand extends Command
{
    protected $signature = 'products:model-kit-shelf-collections
        {--dry-run : Print shelf URLs without calling Shopify}
        {--beginner-only : Only upsert the beginner-kits price collection}';

    protected $description = 'Create/update MG sub-shelf smart collections (mk:* tag rules) for visitor-friendly /collections/ URLs';

    public function handle(ShopifyStorefrontPilotCollectionService $collections): int
    {
        $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');

        if ((bool) $this->option('dry-run')) {
            $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');
            $rows = [];
            if ((bool) $this->option('beginner-only')) {
                $rows[] = ['beginner-kits', $baseUrl.'/collections/beginner-kits'];
            } else {
                foreach (ModelKitShelfCatalog::shelves() as $meta) {
                    $rows[] = [$meta['handle'], $baseUrl.'/collections/'.$meta['handle']];
                }
                $rows[] = ['beginner-kits', $baseUrl.'/collections/beginner-kits'];
            }
            $this->info('Dry-run: would upsert '.count($rows).' model-kit shelf smart collections.');
            $this->table(['Handle', 'URL'], $rows);

            return self::SUCCESS;
        }

        if ((bool) $this->option('beginner-only')) {
            $rows = ['beginner-kits' => $collections->ensureBeginnerKitsCollection()];
        } else {
            $rows = $collections->ensureModelKitShelfCollections();
            $rows['beginner-kits'] = $collections->ensureBeginnerKitsCollection();
        }
        $tableRows = [];
        $failed = false;

        foreach ($rows as $row) {
            $http = $this->verifyCollectionUrl((string) $row['url']);
            $tableRows[] = [
                $row['handle'],
                $row['title'],
                (string) $row['product_count'],
                $row['url'],
                $http,
            ];

            if (! str_starts_with($http, '200') && ! str_starts_with($http, '429')) {
                $failed = true;
            }

            usleep(300_000);
        }

        $this->table(['Handle', 'Title', 'Products', 'URL', 'HTTP'], $tableRows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function verifyCollectionUrl(string $url): string
    {
        try {
            $response = Http::timeout(20)->get($url);

            return (string) $response->status();
        } catch (\Throwable $e) {
            return 'error: '.$e->getMessage();
        }
    }
}
