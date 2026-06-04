<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\Products\ProductRepository;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Product;
use App\Services\Products\ProductExportService;
use App\Services\Products\ShopifyContentExportService;
use App\Support\Products\ProductHoldQty;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use Illuminate\Support\Facades\Log;

final class ShopifyProductCreateFromErpService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly ProductExportService $exports,
        private readonly ShopifyContentExportService $contentExport,
        private readonly ProductRepository $products,
        private readonly ShopifyPublishProductToAllChannelsService $publishAllChannels,
    ) {}

    /**
     * @param  array<string, bool>  $usedHandles
     * @return array{
     *   product_uuid: string,
     *   sku: string,
     *   shopify_gid: string,
     *   handle: string
     * }
     */
    public function createFromProduct(
        Product $product,
        ?string $tunnelBaseUrl,
        array &$usedHandles,
        bool $includeInventory = false,
        string $locationGid = '',
    ): array {
        $this->scopeGuard->assertWriteProductsScope();
        if ($includeInventory && $locationGid !== '') {
            $this->scopeGuard->assertWriteInventoryScope();
        }

        $storedHandle = is_string($product->handle) ? trim($product->handle) : '';
        if ($storedHandle !== '') {
            throw new \InvalidArgumentException(sprintf('Product %s already has handle %s.', (string) $product->sku, $storedHandle));
        }

        $handle = $this->exports->shopifyHandleForProduct($product, $usedHandles);
        $usedHandles[$handle] = true;

        $selling = $product->sellingPrice?->selling_price;
        $price = is_string($selling) ? trim($selling) : '';
        if ($price === '') {
            throw new \InvalidArgumentException(sprintf('Product %s is missing selling price.', (string) $product->sku));
        }

        $files = $this->contentExport->productSetFilesForProduct($product, $tunnelBaseUrl);
        $variant = [
            'optionValues' => [
                [
                    'optionName' => 'Title',
                    'name' => 'Default Title',
                ],
            ],
            'price' => $price,
            'sku' => (string) $product->sku,
        ];

        $barcode = is_string($product->barcode) ? trim($product->barcode) : '';
        if ($barcode !== '') {
            $variant['barcode'] = $barcode;
        }

        if ($includeInventory && $locationGid !== '') {
            $variant['inventoryItem'] = ['tracked' => true];
            $variant['inventoryQuantities'] = [
                [
                    'locationId' => $locationGid,
                    'name' => 'available',
                    'quantity' => ProductHoldQty::sellableForProduct($product),
                ],
            ];
        } else {
            $variant['inventoryItem'] = ['tracked' => false];
        }

        if ($files !== []) {
            $variant['file'] = $files[0];
        }

        $productSet = [
            'title' => (string) $product->description,
            'descriptionHtml' => $this->contentExport->bodyHtmlForProduct($product),
            'handle' => $handle,
            'productType' => trim((string) ($product->type ?? '')),
            'tags' => $this->exports->shopifyTagsListForProduct($product),
            'status' => $this->exports->shopifyStatusEnumForProduct($product),
            'productOptions' => [
                [
                    'name' => 'Title',
                    'values' => [
                        ['name' => 'Default Title'],
                    ],
                ],
            ],
            'variants' => [$variant],
        ];

        if ($files !== []) {
            $productSet['files'] = $files;
        }

        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.product_set.start', [
            'sku' => (string) $product->sku,
            'handle' => $handle,
            'images' => count($files),
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::PRODUCT_SET, [
            'synchronous' => true,
            'productSet' => $productSet,
        ]);

        Log::channel('shopify')->info('shopify.write.product_set.finish', [
            'sku' => (string) $product->sku,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $payload = is_array($response['data']['productSet'] ?? null) ? $response['data']['productSet'] : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify productSet returned no payload.');
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
                $messages !== [] ? implode('; ', $messages) : 'Shopify productSet returned user errors.',
            );
        }

        $created = is_array($payload['product'] ?? null) ? $payload['product'] : null;
        $shopifyGid = is_string($created['id'] ?? null) ? trim($created['id']) : '';
        $createdHandle = is_string($created['handle'] ?? null) ? trim($created['handle']) : '';
        if ($shopifyGid === '' || $createdHandle === '') {
            throw new ShopifyGraphQlException('Shopify productSet succeeded without product id/handle.');
        }

        $product->handle = $createdHandle;
        $this->products->save($product);

        $this->publishAllChannels->publishWhenEligible($product, $shopifyGid);

        return [
            'product_uuid' => (string) $product->uuid,
            'sku' => (string) $product->sku,
            'shopify_gid' => $shopifyGid,
            'handle' => $createdHandle,
        ];
    }
}
