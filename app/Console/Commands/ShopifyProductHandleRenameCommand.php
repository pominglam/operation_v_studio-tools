<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DAL\Products\ProductRepository;
use App\Services\Shopify\Admin\Write\ShopifyInventoryLocationResolver;
use App\Services\Shopify\Admin\Write\ShopifyProductCreateFromErpService;
use App\Services\Shopify\Admin\Write\ShopifyProductHandleRenameService;
use App\Services\Shopify\Admin\Write\ShopifyProductMirrorBySkuResolver;
use App\Services\Shopify\ShopifyImageTunnelLeaseService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopify:product-handle-rename')]
final class ShopifyProductHandleRenameCommand extends Command
{
    protected $signature = 'shopify:product-handle-rename
        {sku : Product SKU}
        {handle : Target Shopify handle}
        {--create : Create in Shopify when no mirror exists (uses slugger if ERP handle is empty)}';

    protected $description = 'Set ERP handle and rename on Shopify (Shopify auto-redirects old product URLs).';

    public function handle(
        ShopifyProductHandleRenameService $rename,
        ShopifyProductCreateFromErpService $create,
        ShopifyProductMirrorBySkuResolver $mirrorBySku,
        ProductRepository $products,
        ShopifyInventoryLocationResolver $locationResolver,
        ShopifyImageTunnelLeaseService $tunnelLease,
    ): int {
        $sku = trim((string) $this->argument('sku'));
        $handle = trim((string) $this->argument('handle'));

        $product = $products->findBySkus([$sku])->first();
        if ($product === null) {
            $this->error(sprintf('Product %s not found.', $sku));

            return self::FAILURE;
        }

        $mirror = $mirrorBySku->resolve($sku);
        $hasMirror = $mirror !== null && $mirrorBySku->isUpsertableMirror($mirror);

        if (! $hasMirror && $this->option('create')) {
            return $this->createInShopify($create, $products, $locationResolver, $tunnelLease, $product, $handle);
        }

        try {
            $result = $rename->rename($product, $handle);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->reportResult($result);

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *   sku: string,
     *   old_handle: string|null,
     *   new_handle: string,
     *   shopify_gid: string|null,
     *   shopify_updated: bool
     * }  $result
     */
    private function reportResult(array $result): void
    {
        $this->info(sprintf(
            'SKU %s: %s -> %s%s',
            $result['sku'],
            $result['old_handle'] ?? '(none)',
            $result['new_handle'],
            $result['shopify_updated'] ? ' (Shopify updated)' : ' (ERP only)',
        ));

        if ($result['shopify_updated'] && $result['old_handle'] !== null && $result['old_handle'] !== '') {
            $this->line('Shopify redirects /products/'.$result['old_handle'].' -> /products/'.$result['new_handle'].'.');
        }
    }

    private function createInShopify(
        ShopifyProductCreateFromErpService $create,
        ProductRepository $products,
        ShopifyInventoryLocationResolver $locationResolver,
        ShopifyImageTunnelLeaseService $tunnelLease,
        \App\Models\Product $product,
        string $expectedHandle,
    ): int {
        if (is_string($product->handle) && trim($product->handle) !== '') {
            $this->error('Product already has a handle; use rename without --create.');

            return self::FAILURE;
        }

        $usedHandles = [];
        $lease = $tunnelLease->acquire();
        try {
            $created = $create->createFromProduct(
                $product,
                $lease->tunnelUrl,
                $usedHandles,
                includeInventory: true,
                locationGid: $locationResolver->resolveLocationGid(),
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $lease->release();
        }

        if ($created['handle'] !== $expectedHandle) {
            $this->warn(sprintf(
                'Created with handle %s (expected %s).',
                $created['handle'],
                $expectedHandle,
            ));
        }

        $this->info(sprintf(
            'SKU %s created in Shopify as /products/%s (%s).',
            $created['sku'],
            $created['handle'],
            $created['shopify_gid'],
        ));

        return self::SUCCESS;
    }
}
