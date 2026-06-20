<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Write\ShopifyStorefrontPilotCollectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ProductsStorefrontPushHubCommand extends Command
{
    protected $signature = 'products:storefront-push-hub
        {--dry-run : Print actions without calling Shopify}';

    protected $description = 'Create/update the tools-and-supplies hub smart collection (union of all ts:dept:* tags).';

    public function handle(ShopifyStorefrontPilotCollectionService $collections): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url', 'https://operationvstudio.com'), '/');

        if ($dryRun) {
            $this->info('Dry-run: would upsert smart collection tools-and-supplies (OR union of all ts:dept:* tags).');
            $this->line('URL: '.$baseUrl.'/collections/tools-and-supplies');

            return self::SUCCESS;
        }

        $row = $collections->ensureToolsAndSuppliesHubCollection();
        $http = $this->verifyCollectionUrl((string) $row['url']);

        $this->table(
            ['Handle', 'Title', 'Products', 'URL', 'HTTP'],
            [[
                $row['handle'],
                $row['title'],
                (string) $row['product_count'],
                $row['url'],
                $http,
            ]],
        );

        if (! str_starts_with($http, '200')) {
            $this->error('Hub collection URL did not return HTTP 200.');

            return self::FAILURE;
        }

        if ((int) $row['product_count'] <= 0) {
            $this->error('Hub collection has zero products — department tags may not have propagated yet.');

            return self::FAILURE;
        }

        $this->info('Tools & supplies hub collection verified.');

        return self::SUCCESS;
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
