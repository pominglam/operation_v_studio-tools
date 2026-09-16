<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Support\Facades\Log;

final class ShopifyStorePreorderProductWriter
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
    ) {}

    public function assertCanWrite(): void
    {
        $this->scopeGuard->assertWriteProductsScope();
    }

    /**
     * @param  list<array{namespace: string, key: string, type: string, value: string}>  $fields
     */
    public function setOfferMetafields(string $productGid, array $fields): void
    {
        $this->assertCanWrite();
        if ($fields === []) {
            return;
        }

        $metafields = [];
        foreach ($fields as $field) {
            $metafields[] = [
                'ownerId' => $productGid,
                'namespace' => $field['namespace'],
                'key' => $field['key'],
                'type' => $field['type'],
                'value' => $field['value'],
            ];
        }

        $response = $this->client->query(ShopifyAdminGraphQlMutations::METAFIELDS_SET, [
            'metafields' => $metafields,
        ]);
        $this->assertNoUserErrors($response['data']['metafieldsSet'] ?? null, 'metafieldsSet');
    }

    public function tag(string $productGid, string $sku): void
    {
        $this->assertCanWrite();
        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.store_preorder.tag.start', [
            'sku' => $sku,
            'product_gid' => $productGid,
        ]);
        $response = $this->client->query(ShopifyAdminGraphQlMutations::TAGS_ADD, [
            'id' => $productGid,
            'tags' => [StorefrontTag::STORE_PREORDER],
        ]);
        Log::channel('shopify')->info('shopify.write.store_preorder.tag.finish', [
            'sku' => $sku,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        $this->assertNoUserErrors($response['data']['tagsAdd'] ?? null, 'tagsAdd');
    }

    public function untag(string $productGid, string $sku): void
    {
        $this->assertCanWrite();
        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.store_preorder.untag.start', [
            'sku' => $sku,
            'product_gid' => $productGid,
        ]);
        $response = $this->client->query(ShopifyAdminGraphQlMutations::TAGS_REMOVE, [
            'id' => $productGid,
            'tags' => [StorefrontTag::STORE_PREORDER],
        ]);
        Log::channel('shopify')->info('shopify.write.store_preorder.untag.finish', [
            'sku' => $sku,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        $this->assertNoUserErrors($response['data']['tagsRemove'] ?? null, 'tagsRemove');
    }

    public function delete(string $productGid, string $sku): void
    {
        $this->assertCanWrite();
        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.store_preorder.delete.start', [
            'sku' => $sku,
            'product_gid' => $productGid,
        ]);
        $response = $this->client->query(ShopifyAdminGraphQlMutations::PRODUCT_DELETE, [
            'input' => ['id' => $productGid],
        ]);
        Log::channel('shopify')->info('shopify.write.store_preorder.delete.finish', [
            'sku' => $sku,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        $this->assertNoUserErrors($response['data']['productDelete'] ?? null, 'productDelete');
    }

    private function assertNoUserErrors(mixed $payload, string $operation): void
    {
        if (! is_array($payload)) {
            throw new ShopifyGraphQlException('Shopify '.$operation.' returned no payload.');
        }

        $userErrors = is_array($payload['userErrors'] ?? null) ? $payload['userErrors'] : [];
        if ($userErrors === []) {
            return;
        }

        $messages = [];
        foreach ($userErrors as $error) {
            $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        throw new ShopifyGraphQlException(
            $messages !== [] ? implode('; ', $messages) : 'Shopify '.$operation.' returned user errors.',
        );
    }
}
