<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\Products\ProductRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Product;
use App\Services\Products\ProductExportService;
use App\Services\Products\ShopifyContentExportService;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Support\Products\ProductHoldQty;
use Illuminate\Support\Facades\Log;

final class ShopifyProductUpsertFromErpService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly ProductExportService $exports,
        private readonly ShopifyContentExportService $contentExport,
        private readonly ProductRepository $products,
        private readonly ShopifyProductMirrorBySkuResolver $mirrorBySku,
        private readonly ShopifyPublishProductToAllChannelsService $publishAllChannels,
        private readonly ShopifyProductMediaService $productMedia,
    ) {}

    /**
     * @param  array<string, bool>  $usedHandles
     * @return array{
     *   product_uuid: string,
     *   sku: string,
     *   shopify_gid: string,
     *   handle: string,
     *   action: 'created'|'updated'
     * }
     */
    public function upsertFromProduct(
        Product $product,
        ?string $tunnelBaseUrl,
        string $locationGid,
        array &$usedHandles,
        ?ShopifyProductPushOptionsDTO $options = null,
    ): array {
        $options = $options ?? ShopifyProductPushOptionsDTO::allEnabled();

        $this->scopeGuard->assertWriteProductsScope();
        if ($options->requiresInventoryScope() && $locationGid !== '') {
            $this->scopeGuard->assertWriteInventoryScope();
        }

        $mirror = $this->mirrorBySku->resolve((string) $product->sku);
        $storedHandle = is_string($product->handle) ? trim($product->handle) : '';
        $isUpdate = $mirror !== null && $this->mirrorBySku->isUpsertableMirror($mirror);

        if ($isUpdate) {
            return $this->upsertExisting($product, $tunnelBaseUrl, $locationGid, $mirror, $options);
        }

        if ($storedHandle !== '') {
            throw new \InvalidArgumentException(sprintf(
                'Product %s has handle %s but no Shopify mirror (ACTIVE/DRAFT). Run product sync or pull handles first.',
                (string) $product->sku,
                $storedHandle,
            ));
        }

        return $this->upsertCreate($product, $tunnelBaseUrl, $locationGid, $usedHandles, $options);
    }

    /**
     * @param  array{
     *   product_gid: string,
     *   variant_gid: string,
     *   inventory_item_gid: string|null,
     *   shopify_handle: string|null,
     *   shopify_status: string|null
     * }  $mirror
     * @return array{
     *   product_uuid: string,
     *   sku: string,
     *   shopify_gid: string,
     *   handle: string,
     *   action: 'updated'
     * }
     */
    private function upsertExisting(
        Product $product,
        ?string $tunnelBaseUrl,
        string $locationGid,
        array $mirror,
        ShopifyProductPushOptionsDTO $options,
    ): array {
        $handle = is_string($product->handle) && trim($product->handle) !== ''
            ? trim($product->handle)
            : (string) ($mirror['shopify_handle'] ?? '');

        $productSet = $this->buildProductSet($product, $handle, $tunnelBaseUrl, $locationGid, $options, true);
        $productSet['id'] = $mirror['product_gid'];
        $productSet['variants'][0]['id'] = $mirror['variant_gid'];

        if ($options->images && $this->hasProductSetFiles($productSet)) {
            $this->productMedia->clearExistingMedia((string) $mirror['product_gid']);
        }

        $payload = $this->executeProductSet($product, $handle, $productSet, 'update', $options);

        return [
            'product_uuid' => (string) $product->uuid,
            'sku' => (string) $product->sku,
            'shopify_gid' => $payload['shopify_gid'],
            'handle' => $payload['handle'],
            'action' => 'updated',
        ];
    }

    /**
     * @param  array<string, bool>  $usedHandles
     * @return array{
     *   product_uuid: string,
     *   sku: string,
     *   shopify_gid: string,
     *   handle: string,
     *   action: 'created'
     * }
     */
    private function upsertCreate(
        Product $product,
        ?string $tunnelBaseUrl,
        string $locationGid,
        array &$usedHandles,
        ShopifyProductPushOptionsDTO $options,
    ): array {
        $handle = $this->exports->shopifyHandleForProduct($product, $usedHandles);
        $usedHandles[$handle] = true;

        $productSet = $this->buildProductSet($product, $handle, $tunnelBaseUrl, $locationGid, $options, false);
        $payload = $this->executeProductSet($product, $handle, $productSet, 'create', $options);

        $product->handle = $payload['handle'];
        $this->products->save($product);

        return [
            'product_uuid' => (string) $product->uuid,
            'sku' => (string) $product->sku,
            'shopify_gid' => $payload['shopify_gid'],
            'handle' => $payload['handle'],
            'action' => 'created',
        ];
    }

    /**
     * @return array{shopify_gid: string, handle: string}
     */
    private function executeProductSet(
        Product $product,
        string $handle,
        array $productSet,
        string $mode,
        ShopifyProductPushOptionsDTO $options,
    ): array {
        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.product_set.start', [
            'sku' => (string) $product->sku,
            'handle' => $handle,
            'mode' => $mode,
            'images' => count(is_array($productSet['files'] ?? null) ? $productSet['files'] : []),
        ]);

        ShopifyProductSetPayloadValidator::assertValid($productSet);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::PRODUCT_SET, [
            'synchronous' => true,
            'productSet' => $productSet,
        ]);

        Log::channel('shopify')->info('shopify.write.product_set.finish', [
            'sku' => (string) $product->sku,
            'mode' => $mode,
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

        if ($options->salesChannels || $options->publishStatus) {
            $this->publishAllChannels->publishWhenEligible($product, $shopifyGid);
        }

        return [
            'shopify_gid' => $shopifyGid,
            'handle' => $createdHandle,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProductSet(
        Product $product,
        string $handle,
        ?string $tunnelBaseUrl,
        string $locationGid,
        ShopifyProductPushOptionsDTO $options,
        bool $isUpdate,
    ): array {
        $files = $options->images
            ? $this->contentExport->productSetFilesForProduct($product, $tunnelBaseUrl)
            : [];

        $variant = [
            'optionValues' => [
                [
                    'optionName' => 'Title',
                    'name' => 'Default Title',
                ],
            ],
        ];

        if ($options->price || ! $isUpdate) {
            $variant['price'] = $this->requireSellingPrice($product);
        }

        if ($options->info || ! $isUpdate) {
            $variant['sku'] = (string) $product->sku;
            $barcode = is_string($product->barcode) ? trim($product->barcode) : '';
            if ($barcode !== '') {
                $variant['barcode'] = $barcode;
            }
        }

        if ($options->quantities && $locationGid !== '') {
            $variant['inventoryItem'] = ['tracked' => true];
            $variant['inventoryQuantities'] = [
                [
                    'locationId' => $locationGid,
                    'name' => 'available',
                    'quantity' => ProductHoldQty::sellableForProduct($product),
                ],
            ];
        } elseif (! $isUpdate) {
            $variant['inventoryItem'] = ['tracked' => false];
        }

        if ($options->images && $files !== []) {
            $variant['file'] = $files[0];
        }

        $productSet = [
            'variants' => [$variant],
            'productOptions' => $this->defaultProductOptions(),
        ];

        if (! $isUpdate) {
            $productSet['handle'] = $handle;
            $productSet['title'] = (string) $product->description;
        }

        if ($options->info || ! $isUpdate) {
            $productSet['title'] = (string) $product->description;
            $productSet['descriptionHtml'] = $this->contentExport->bodyHtmlForProduct($product);
            $productType = trim((string) ($product->type ?? ''));
            if ($productType !== '') {
                $productSet['productType'] = $productType;
            }
            $tags = $this->exports->shopifyTagsListForProduct($product);
            if ($tags !== []) {
                $productSet['tags'] = $tags;
            }
        }

        if ($options->publishStatus || ! $isUpdate) {
            $productSet['status'] = $this->exports->shopifyStatusEnumForProduct($product);
        }

        if ($options->images && $files !== []) {
            $productSet['files'] = $files;
        }

        return $productSet;
    }

    /**
     * @param  array<string, mixed>  $productSet
     */
    private function hasProductSetFiles(array $productSet): bool
    {
        $files = $productSet['files'] ?? null;

        return is_array($files) && $files !== [];
    }

    private function requireSellingPrice(Product $product): string
    {
        $selling = $product->sellingPrice?->selling_price;
        $price = is_string($selling) ? trim($selling) : '';
        if ($price === '') {
            throw new \InvalidArgumentException(sprintf('Product %s is missing selling price.', (string) $product->sku));
        }

        return $price;
    }

    /**
     * Shopify requires productOptions whenever variants (with optionValues) are sent,
     * including partial updates on existing products.
     *
     * @return array<int, array{name: string, values: array<int, array{name: string}>}>
     */
    private function defaultProductOptions(): array
    {
        return [
            [
                'name' => 'Title',
                'values' => [
                    ['name' => 'Default Title'],
                ],
            ],
        ];
    }
}
