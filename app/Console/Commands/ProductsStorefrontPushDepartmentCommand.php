<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\Products\ProductRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Models\Product;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Write\ShopifyInventoryLocationResolver;
use App\Services\Shopify\Admin\Write\ShopifyProductUpsertFromErpService;
use App\Services\Shopify\Admin\Write\ShopifyStorefrontPilotCollectionService;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ProductsStorefrontPushDepartmentCommand extends Command
{
    protected $signature = 'products:storefront-push-department
        {department : Department handle (e.g. sanding)}
        {--push-only : Push product tags only; skip collection setup}
        {--collections-only : Create/update collection only; skip product push}
        {--dry-run : Print actions without calling Shopify}';

    protected $description = 'Push ts:* tags for one enabled department to Shopify and ensure its smart collection.';

    public function handle(
        ProductRepository $products,
        ProductStorefrontClassifier $classifier,
        ShopifyProductUpsertFromErpService $upsert,
        ShopifyInventoryLocationResolver $locationResolver,
        ShopifyStorefrontPilotCollectionService $collections,
        ShopifyAdminGraphQlClientInterface $client,
    ): int {
        $department = strtolower(trim((string) $this->argument('department')));
        $enabled = config('storefront_classification.enabled_departments', []);
        if (! in_array($department, $enabled, true)) {
            $this->error("Department \"{$department}\" is not enabled in storefront_classification config.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $pushOnly = (bool) $this->option('push-only');
        $collectionsOnly = (bool) $this->option('collections-only');

        if ($pushOnly && $collectionsOnly) {
            $this->error('Use only one of --push-only or --collections-only.');

            return self::FAILURE;
        }

        $departmentProducts = $this->departmentProducts($classifier, $department);
        if ($departmentProducts === []) {
            $this->error("No ERP products classified for department \"{$department}\".");

            return self::FAILURE;
        }

        $this->info('Department '.$department.': '.$departmentProducts->count().' ERP products.');

        $pushRows = [];
        if (! $collectionsOnly) {
            $pushRows = $this->pushDepartmentProducts(
                $products,
                $departmentProducts,
                $classifier,
                $upsert,
                $locationResolver,
                $client,
                $dryRun,
            );
        }

        $collectionRows = [];
        if (! $pushOnly) {
            if ($dryRun) {
                $baseUrl = rtrim((string) config('storefront_classification.storefront_base_url'), '/');
                $collectionRows[$department] = [
                    'gid' => '(dry-run)',
                    'handle' => $department,
                    'title' => ucfirst($department),
                    'product_count' => 0,
                    'url' => $baseUrl.'/collections/'.$department,
                ];
                $this->warn('Dry-run: skipped collection create/publish.');
            } else {
                $collectionRows = $collections->ensureDepartmentCollections([$department]);
            }
        }

        if ($pushRows !== []) {
            $this->info('Product push results:');
            $this->table(
                ['SKU', 'Action', 'Shopify GID', 'Tags on Shopify', 'Expected ts:dept'],
                $pushRows,
            );
        }

        if ($collectionRows !== []) {
            $this->info('Collection results:');
            $this->table(
                ['Handle', 'Title', 'Products', 'URL', 'HTTP'],
                array_map(function (array $row): array {
                    $http = $this->verifyCollectionUrl((string) $row['url']);

                    return [
                        $row['handle'],
                        $row['title'],
                        (string) $row['product_count'],
                        $row['url'],
                        $http,
                    ];
                }, array_values($collectionRows)),
            );
        }

        if ($dryRun) {
            $this->warn('Dry-run complete — no Shopify writes performed.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($pushRows as $row) {
            if (($row[3] ?? '') === 'VERIFY_FAILED') {
                $failed = true;
            }
        }
        foreach ($collectionRows as $row) {
            if (! str_starts_with($this->verifyCollectionUrl((string) $row['url']), '200')) {
                $failed = true;
            }
            if ((int) ($row['product_count'] ?? 0) <= 0) {
                $failed = true;
                $this->error("Collection {$row['handle']} has zero products — tags may not have propagated yet.");
            }
        }

        if ($failed) {
            $this->error('Verification failed for one or more department resources.');

            return self::FAILURE;
        }

        $this->info("Department {$department} push and collection verified.");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function departmentProducts(ProductStorefrontClassifier $classifier, string $department)
    {
        return Product::query()
            ->orderBy('sku')
            ->get()
            ->filter(function (Product $product) use ($classifier, $department): bool {
                return $classifier->classify($product)->department === $department;
            })
            ->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $departmentProducts
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private function pushDepartmentProducts(
        ProductRepository $products,
        $departmentProducts,
        ProductStorefrontClassifier $classifier,
        ShopifyProductUpsertFromErpService $upsert,
        ShopifyInventoryLocationResolver $locationResolver,
        ShopifyAdminGraphQlClientInterface $client,
        bool $dryRun,
    ): array {
        $options = new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        );

        $locationGid = $locationResolver->resolveLocationGid();
        $usedHandles = [];
        $rows = [];

        foreach ($departmentProducts as $product) {
            $classification = $classifier->classify($product);
            $expectedDept = $classification->department ?? '-';
            $expectedTags = implode(', ', $classification->shopifyTags);

            if ($dryRun) {
                $rows[] = [
                    (string) $product->sku,
                    'dry-run',
                    '-',
                    $expectedTags,
                    $expectedDept,
                ];

                continue;
            }

            $loaded = $products->listForShopifyContentExportByUuids([(string) $product->uuid])->first();
            if ($loaded === null) {
                $rows[] = [(string) $product->sku, 'error', '-', 'ERP row not found', $expectedDept];

                continue;
            }

            try {
                $result = $upsert->upsertFromProduct($loaded, null, $locationGid, $usedHandles, $options);
                $verifiedTags = $this->verifyProductTags($client, (string) $result['shopify_gid'], $classification->shopifyTags);
                $rows[] = [
                    (string) $product->sku,
                    (string) $result['action'],
                    (string) $result['shopify_gid'],
                    $verifiedTags ? implode(', ', $verifiedTags) : 'VERIFY_FAILED',
                    $expectedDept,
                ];
            } catch (\Throwable $e) {
                $rows[] = [(string) $product->sku, 'error', '-', $e->getMessage(), $expectedDept];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $expectedTags
     * @return array<int, string>|null
     */
    private function verifyProductTags(
        ShopifyAdminGraphQlClientInterface $client,
        string $productGid,
        array $expectedTags,
    ): ?array {
        $response = $client->query(ShopifyAdminGraphQlQueries::PRODUCT_TAGS_BY_ID, [
            'id' => $productGid,
        ]);
        $node = is_array($response['data']['product'] ?? null) ? $response['data']['product'] : null;
        if ($node === null) {
            return null;
        }

        $tags = is_array($node['tags'] ?? null) ? $node['tags'] : [];
        $tags = array_values(array_filter(array_map('strval', $tags), static fn (string $t): bool => trim($t) !== ''));

        foreach ($expectedTags as $expected) {
            if (! in_array($expected, $tags, true)) {
                return null;
            }
        }

        return $tags;
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
