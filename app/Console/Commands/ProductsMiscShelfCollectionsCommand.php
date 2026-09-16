<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Write\ShopifyStorefrontPilotCollectionService;
use App\Support\Products\Storefront\MiscShelfCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ProductsMiscShelfCollectionsCommand extends Command
{
    protected $signature = 'products:misc-shelf-collections
        {--dry-run : Print shelf URLs without calling Shopify}';

    protected $description = 'Create/update Miscellaneous smart collections (misc:* tag rules) for /collections/ URLs';

    public function handle(ShopifyStorefrontPilotCollectionService $collections): int
    {
        $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');

        if ((bool) $this->option('dry-run')) {
            $rows = [];
            foreach (MiscShelfCatalog::shelves() as $meta) {
                $rows[] = [$meta['handle'], $baseUrl.'/collections/'.$meta['handle']];
            }
            $this->info('Dry-run: would upsert '.count($rows).' misc shelf smart collections.');
            $this->table(['Handle', 'URL'], $rows);

            return self::SUCCESS;
        }

        $rows = $collections->ensureMiscShelfCollections();
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
