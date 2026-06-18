<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use Illuminate\Support\Facades\Log;

final class ShopifyProductMediaService
{
    private const int MEDIA_PAGE_SIZE = 50;

    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
    ) {}

    public function clearExistingMedia(string $productGid): void
    {
        $productGid = trim($productGid);
        if ($productGid === '') {
            return;
        }

        $mediaIds = $this->listMediaIds($productGid);
        if ($mediaIds === []) {
            return;
        }

        Log::channel('shopify')->info('shopify.write.product_media_clear.start', [
            'product_gid' => $productGid,
            'media_count' => count($mediaIds),
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::PRODUCT_DELETE_MEDIA, [
            'productId' => $productGid,
            'mediaIds' => $mediaIds,
        ]);

        $payload = is_array($response['data']['productDeleteMedia'] ?? null)
            ? $response['data']['productDeleteMedia']
            : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify productDeleteMedia returned no payload.');
        }

        $this->assertNoUserErrors(
            $payload,
            'Shopify productDeleteMedia returned user errors.',
        );

        Log::channel('shopify')->info('shopify.write.product_media_clear.finish', [
            'product_gid' => $productGid,
            'deleted_count' => count(is_array($payload['deletedMediaIds'] ?? null) ? $payload['deletedMediaIds'] : []),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function listMediaIds(string $productGid): array
    {
        $ids = [];
        $after = null;

        do {
            $response = $this->client->query(ShopifyAdminGraphQlQueries::PRODUCT_MEDIA_IDS, [
                'id' => $productGid,
                'first' => self::MEDIA_PAGE_SIZE,
                'after' => $after,
            ]);

            $product = is_array($response['data']['product'] ?? null) ? $response['data']['product'] : null;
            if ($product === null) {
                break;
            }

            $media = is_array($product['media'] ?? null) ? $product['media'] : [];
            $nodes = is_array($media['nodes'] ?? null) ? $media['nodes'] : [];
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $id = is_string($node['id'] ?? null) ? trim($node['id']) : '';
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            $pageInfo = is_array($media['pageInfo'] ?? null) ? $media['pageInfo'] : [];
            $hasNext = ($pageInfo['hasNextPage'] ?? false) === true;
            $after = $hasNext && is_string($pageInfo['endCursor'] ?? null) ? $pageInfo['endCursor'] : null;
        } while ($after !== null);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoUserErrors(array $payload, string $fallbackMessage): void
    {
        /** @var array<int, array{message?:mixed}> $errors */
        $errors = [];
        foreach (['mediaUserErrors', 'userErrors'] as $key) {
            if (! is_array($payload[$key] ?? null)) {
                continue;
            }
            foreach ($payload[$key] as $error) {
                if (is_array($error)) {
                    $errors[] = $error;
                }
            }
        }

        if ($errors === []) {
            return;
        }

        $messages = [];
        foreach ($errors as $error) {
            $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        throw new ShopifyGraphQlException(
            $messages !== [] ? implode('; ', $messages) : $fallbackMessage,
        );
    }
}
