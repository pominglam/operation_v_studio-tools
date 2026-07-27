<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Product;
use App\Services\Products\LatestArrivalCatalogOrderService;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\Shopify\Admin\Support\ShopifyAsyncJobWaitService;
use Illuminate\Support\Facades\Log;

final class ShopifyLatestArrivalsCollectionReorderService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly LatestArrivalCatalogOrderService $catalogOrder,
        private readonly ShopifyProductMirrorBySkuResolver $mirrorBySku,
        private readonly ShopifyAsyncJobWaitService $asyncJobWait,
    ) {}

    /**
     * @return array{
     *   attempted: bool,
     *   collection_gid: string|null,
     *   product_count: int,
     *   moves_sent: int,
     *   job_id: string|null,
     *   job_done: bool,
     *   job_wait_timed_out: bool,
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

        $jobDone = false;
        $jobWaitTimedOut = false;
        if ($jobId !== null) {
            $jobDone = $this->asyncJobWait->waitUntilDone(
                $jobId,
                (int) config('latest_arrival.collection_reorder_job_max_wait_seconds', 120),
                (int) config('latest_arrival.collection_reorder_job_poll_ms', 1000),
            );
            $jobWaitTimedOut = ! $jobDone;
        }

        Log::channel('shopify')->info('shopify.write.collection_reorder_products.finish', [
            'collection_gid' => $collectionGid,
            'moves_sent' => count($moves),
            'job_id' => $jobId,
            'job_done' => $jobDone,
            'job_wait_timed_out' => $jobWaitTimedOut,
        ]);

        $skippedReason = null;
        if (count($moves) < count($gids)) {
            $skippedReason = 'moves_truncated_at_limit';
        } elseif ($jobWaitTimedOut) {
            $skippedReason = 'reorder_job_wait_timeout';
        }

        return [
            'attempted' => true,
            'collection_gid' => $collectionGid,
            'product_count' => count($gids),
            'moves_sent' => count($moves),
            'job_id' => $jobId,
            'job_done' => $jobDone,
            'job_wait_timed_out' => $jobWaitTimedOut,
            'skipped_reason' => $skippedReason,
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
     *   job_done: bool,
     *   job_wait_timed_out: bool,
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
            'job_done' => false,
            'job_wait_timed_out' => false,
            'skipped_reason' => $reason,
        ];
    }
}
