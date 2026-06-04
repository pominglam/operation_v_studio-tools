<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Product;
use App\Services\Products\ProductExportService;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ShopifyPublishProductToAllChannelsService
{
    private const string PUBLICATION_IDS_CACHE_KEY = 'shopify.publication_ids';

    private const int PUBLICATION_IDS_CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly ProductExportService $exports,
    ) {}

    /**
     * Publishes the product to every shop publication when ERP flags it for Shopify visibility.
     */
    public function publishWhenEligible(Product $product, string $shopifyProductGid): void
    {
        if ($this->exports->shopifyStatusEnumForProduct($product) !== 'ACTIVE') {
            return;
        }

        if (! $this->scopeGuard->hasWritePublicationsScope()) {
            Log::channel('shopify')->warning('shopify.write.publish_all_channels.skipped', [
                'sku' => (string) $product->sku,
                'reason' => 'missing_write_publications_scope',
            ]);

            return;
        }

        $this->publishToAllChannels($shopifyProductGid, (string) $product->sku);
    }

    public function publishToAllChannels(string $shopifyProductGid, string $skuForLog = ''): void
    {
        $this->scopeGuard->assertWritePublicationsScope();

        $publicationIds = $this->publicationIds();
        if ($publicationIds === []) {
            Log::channel('shopify')->warning('shopify.write.publish_all_channels.skipped', [
                'sku' => $skuForLog,
                'reason' => 'no_publications',
            ]);

            return;
        }

        $input = [];
        foreach ($publicationIds as $publicationId) {
            $input[] = ['publicationId' => $publicationId];
        }

        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.publish_all_channels.start', [
            'sku' => $skuForLog,
            'product_gid' => $shopifyProductGid,
            'publication_count' => count($input),
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::PUBLISHABLE_PUBLISH, [
            'id' => $shopifyProductGid,
            'input' => $input,
        ]);

        Log::channel('shopify')->info('shopify.write.publish_all_channels.finish', [
            'sku' => $skuForLog,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $payload = is_array($response['data']['publishablePublish'] ?? null)
            ? $response['data']['publishablePublish']
            : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify publishablePublish returned no payload.');
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
                $messages !== [] ? implode('; ', $messages) : 'Shopify publishablePublish returned user errors.',
            );
        }
    }

    /**
     * @return array<int, string>
     */
    public function publicationIds(): array
    {
        if (! $this->scopeGuard->hasReadPublicationsScope()) {
            return [];
        }

        /** @var array<int, string> $cached */
        $cached = Cache::remember(
            self::PUBLICATION_IDS_CACHE_KEY,
            self::PUBLICATION_IDS_CACHE_TTL_SECONDS,
            fn (): array => $this->fetchPublicationIds(),
        );

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    private function fetchPublicationIds(): array
    {
        $ids = [];
        $after = null;

        do {
            $response = $this->client->query(ShopifyAdminGraphQlQueries::PUBLICATIONS_PAGE, [
                'first' => 50,
                'after' => $after,
            ]);

            $conn = is_array($response['data']['publications'] ?? null)
                ? $response['data']['publications']
                : null;
            if ($conn === null) {
                break;
            }

            $nodes = is_array($conn['nodes'] ?? null) ? $conn['nodes'] : [];
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $id = is_string($node['id'] ?? null) ? trim($node['id']) : '';
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            $pageInfo = is_array($conn['pageInfo'] ?? null) ? $conn['pageInfo'] : [];
            $hasNext = ($pageInfo['hasNextPage'] ?? false) === true;
            $after = $hasNext && is_string($pageInfo['endCursor'] ?? null) ? $pageInfo['endCursor'] : null;
        } while ($after !== null);

        return array_values(array_unique($ids));
    }
}
