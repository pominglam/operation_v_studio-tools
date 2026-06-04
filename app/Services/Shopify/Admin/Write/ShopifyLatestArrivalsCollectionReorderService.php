<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Product;
use App\Services\Products\LatestArrivalCatalogOrderService;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use Illuminate\Support\Facades\Log;

final class ShopifyLatestArrivalsCollectionReorderService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly LatestArrivalCatalogOrderService $catalogOrder,
        private readonly ShopifyProductMirrorBySkuResolver $mirrorBySku,
    ) {}

    /**
     * @return array{
     *   attempted: bool,
     *   collection_gid: string|null,
     *   product_count: int,
     *   moves_sent: int,
     *   job_id: string|null,
     *   skipped_reason: string|null
     * }
     */
    public function reorderFromCatalogOrder(): array
    {
        $collectionGid = $this->collectionGid();
        if ($collectionGid === null) {
            return $this->skipped('collection_gid_not_configured', null, 0);
        }

        try {
            $this->scopeGuard->assertWriteProductsScope();
        } catch (\Throwable $e) {
            return $this->skipped('missing_write_products_scope', $collectionGid, 0);
        }

        $ordered = $this->catalogOrder->orderedLatestArrivalProducts();
        $gids = [];
        foreach ($ordered as $product) {
            $gid = $this->productGidForReorder($product);
            if ($gid !== null) {
                $gids[] = $gid;
            }
        }

        if ($gids === []) {
            return $this->skipped('no_mirrored_products', $collectionGid, 0);
        }

        $limit = (int) config('latest_arrival.collection_reorder_moves_limit', 250);
        $moves = [];
        foreach ($gids as $index => $gid) {
            if (count($moves) >= $limit) {
                break;
            }
            $moves[] = [
                'id' => $gid,
                'newPosition' => (string) $index,
            ];
        }

        $response = $this->client->query(ShopifyAdminGraphQlMutations::COLLECTION_REORDER_PRODUCTS, [
            'id' => $collectionGid,
            'moves' => $moves,
        ]);

        $payload = is_array($response['data']['collectionReorderProducts'] ?? null)
            ? $response['data']['collectionReorderProducts']
            : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify collectionReorderProducts returned no payload.');
        }

        $userErrors = $payload['userErrors'] ?? [];
        if (is_array($userErrors) && $userErrors !== []) {
            $messages = [];
            foreach ($userErrors as $err) {
                if (is_array($err) && is_string($err['message'] ?? null)) {
                    $messages[] = $err['message'];
                }
            }

            throw new ShopifyGraphQlException(
                $messages !== [] ? implode('; ', $messages) : 'Shopify collectionReorderProducts returned user errors.',
            );
        }

        $jobId = is_array($payload['job'] ?? null) && is_string($payload['job']['id'] ?? null)
            ? $payload['job']['id']
            : null;

        Log::channel('shopify')->info('shopify.write.collection_reorder_products.finish', [
            'collection_gid' => $collectionGid,
            'moves_sent' => count($moves),
            'job_id' => $jobId,
        ]);

        return [
            'attempted' => true,
            'collection_gid' => $collectionGid,
            'product_count' => count($gids),
            'moves_sent' => count($moves),
            'job_id' => $jobId,
            'skipped_reason' => count($moves) < count($gids) ? 'moves_truncated_at_limit' : null,
        ];
    }

    private function productGidForReorder(Product $product): ?string
    {
        $mirror = $this->mirrorBySku->resolve((string) $product->sku);
        if ($mirror === null) {
            return null;
        }
        $gid = $mirror['product_gid'] ?? null;

        return is_string($gid) && $gid !== '' ? $gid : null;
    }

    private function collectionGid(): ?string
    {
        $gid = config('latest_arrival.collection_gid');
        if (! is_string($gid)) {
            return null;
        }
        $gid = trim($gid);

        return $gid !== '' ? $gid : null;
    }

    /**
     * @return array{
     *   attempted: bool,
     *   collection_gid: string|null,
     *   product_count: int,
     *   moves_sent: int,
     *   job_id: string|null,
     *   skipped_reason: string|null
     * }
     */
    private function skipped(string $reason, ?string $collectionGid, int $productCount): array
    {
        Log::channel('shopify')->info('shopify.write.collection_reorder_products.skipped', [
            'reason' => $reason,
            'collection_gid' => $collectionGid,
        ]);

        return [
            'attempted' => false,
            'collection_gid' => $collectionGid,
            'product_count' => $productCount,
            'moves_sent' => 0,
            'job_id' => null,
            'skipped_reason' => $reason,
        ];
    }
}
