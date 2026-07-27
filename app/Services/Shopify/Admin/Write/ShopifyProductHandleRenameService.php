<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\Products\ProductRepository;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Product;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use Illuminate\Support\Facades\Log;

final class ShopifyProductHandleRenameService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly ProductRepository $products,
        private readonly ShopifyProductMirrorBySkuResolver $mirrorBySku,
        private readonly ShopifyProductMirrorRefreshService $mirrorRefresh,
    ) {}

    /**
     * @return array{
     *   sku: string,
     *   old_handle: string|null,
     *   new_handle: string,
     *   shopify_gid: string|null,
     *   shopify_updated: bool
     * }
     */
    public function renameBySku(string $sku, string $newHandle): array
    {
        $sku = trim($sku);
        $newHandle = trim($newHandle);
        if ($sku === '' || $newHandle === '') {
            throw new \InvalidArgumentException('SKU and new handle are required.');
        }

        $product = $this->products->findBySkus([$sku])->first();
        if ($product === null) {
            throw new \InvalidArgumentException(sprintf('Product %s not found.', $sku));
        }

        return $this->rename($product, $newHandle);
    }

    /**
     * @return array{
     *   sku: string,
     *   old_handle: string|null,
     *   new_handle: string,
     *   shopify_gid: string|null,
     *   shopify_updated: bool
     * }
     */
    public function rename(Product $product, string $newHandle): array
    {
        $this->scopeGuard->assertWriteProductsScope();

        $newHandle = trim($newHandle);
        if ($newHandle === '') {
            throw new \InvalidArgumentException('New handle cannot be empty.');
        }

        $oldHandle = is_string($product->handle) ? trim($product->handle) : '';
        if ($oldHandle === $newHandle) {
            return [
                'sku' => (string) $product->sku,
                'old_handle' => $oldHandle !== '' ? $oldHandle : null,
                'new_handle' => $newHandle,
                'shopify_gid' => null,
                'shopify_updated' => false,
            ];
        }

        $this->assertHandleAvailable($newHandle, $product);

        $mirror = $this->mirrorBySku->resolve((string) $product->sku);
        $shopifyGid = null;
        $shopifyUpdated = false;

        if ($mirror !== null && $this->mirrorBySku->isUpsertableMirror($mirror)) {
            $shopifyGid = (string) $mirror['product_gid'];
            $this->updateShopifyHandle($shopifyGid, $newHandle, (string) $product->sku, $oldHandle);
            $shopifyUpdated = true;
            $this->mirrorRefresh->refreshByProductGid($shopifyGid);
        }

        $product->handle = $newHandle;
        $this->products->save($product);

        return [
            'sku' => (string) $product->sku,
            'old_handle' => $oldHandle !== '' ? $oldHandle : null,
            'new_handle' => $newHandle,
            'shopify_gid' => $shopifyGid,
            'shopify_updated' => $shopifyUpdated,
        ];
    }

    private function assertHandleAvailable(string $handle, Product $product): void
    {
        $matches = $this->products->findByHandle($handle);
        foreach ($matches as $match) {
            if ($match->id !== $product->id) {
                throw new \InvalidArgumentException(sprintf(
                    'Handle %s is already assigned to SKU %s.',
                    $handle,
                    (string) $match->sku,
                ));
            }
        }
    }

    private function updateShopifyHandle(string $productGid, string $newHandle, string $sku, string $oldHandle): void
    {
        Log::channel('shopify')->info('shopify.write.product_handle_rename.start', [
            'sku' => $sku,
            'old_handle' => $oldHandle,
            'new_handle' => $newHandle,
            'product_gid' => $productGid,
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::PRODUCT_UPDATE, [
            'input' => [
                'id' => $productGid,
                'handle' => $newHandle,
            ],
        ]);

        $payload = is_array($response['data']['productUpdate'] ?? null) ? $response['data']['productUpdate'] : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify productUpdate returned no payload.');
        }

        /** @var array<int, array{field?:mixed, message?:mixed}> $userErrors */
        $userErrors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($userErrors !== []) {
            $messages = [];
            foreach ($userErrors as $error) {
                $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';
                if ($message !== '') {
                    $messages[] = $message;
                }
            }

            throw new ShopifyGraphQlException(
                $messages !== [] ? implode('; ', $messages) : 'Shopify productUpdate returned user errors.',
            );
        }

        $updatedHandle = is_string($payload['product']['handle'] ?? null) ? trim($payload['product']['handle']) : '';
        if ($updatedHandle !== $newHandle) {
            throw new ShopifyGraphQlException(sprintf(
                'Shopify productUpdate returned unexpected handle %s (expected %s).',
                $updatedHandle,
                $newHandle,
            ));
        }

        Log::channel('shopify')->info('shopify.write.product_handle_rename.finish', [
            'sku' => $sku,
            'old_handle' => $oldHandle,
            'new_handle' => $newHandle,
            'product_gid' => $productGid,
            'redirect_note' => 'Shopify auto-creates /products/{old} -> /products/{new} redirect on handle change.',
        ]);
    }
}
