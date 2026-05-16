<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\Diagnostics\ShopifyProductConnectivityProbe;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopify:test-products')]
final class ShopifyTestProductsCommand extends Command
{
    protected $signature = 'shopify:test-products {--limit=10 : Products to fetch from Shopify (max 50)}';

    protected $description = 'Read-only: print first N products from Shopify Admin GraphQL (no local DB writes).';

    public function handle(ShopifyProductConnectivityProbe $probe): int
    {
        $raw = (string) $this->option('limit');
        $limit = is_numeric($raw) ? (int) $raw : 10;
        $limit = max(1, min(50, $limit));

        $this->info("Shopify connectivity preview — first {$limit} product(s) (read-only, no ERP persistence).\n");

        try {
            $rows = $probe->previewProducts($limit);
        } catch (ShopifyAdminConfigurationException $e) {
            $this->error('OAuth / configuration: '.$e->getMessage());

            return self::FAILURE;
        } catch (ShopifyGraphQlException $e) {
            $this->error('Shopify GraphQL: '.$e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('No products returned.');

            return self::SUCCESS;
        }

        $this->table(
            ['GID', 'Title', 'Handle', 'Status', 'Vendor', 'Product type', 'Variant count'],
            array_map(static function (array $r): array {
                return [
                    $r['gid'],
                    mb_substr($r['title'], 0, 80),
                    $r['handle'],
                    $r['status'],
                    mb_substr($r['vendor'], 0, 40),
                    mb_substr($r['product_type'], 0, 40),
                    $r['variant_count_display'],
                ];
            }, $rows),
        );

        return self::SUCCESS;
    }
}
