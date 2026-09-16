<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DTOs\StorePreorders\StorePreorderCollectionReorderResult;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use App\Services\Shopify\Admin\Support\ShopifyAsyncJobWaitService;
use App\Services\StorePreorders\StorePreorderCollectionOrderBuilder;
use Illuminate\Support\Facades\Log;

final class ShopifyStorePreordersCollectionReorderService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
        private readonly StorePreorderCollectionOrderBuilder $orderBuilder,
        private readonly ShopifyAsyncJobWaitService $asyncJobWait,
    ) {}

    public function reorderByCloseDate(): StorePreorderCollectionReorderResult
    {
        $collection = $this->collection();
        if ($collection === null) {
            return $this->skipped('collection_not_found', null, 0);
        }

        try {
            $this->scopeGuard->assertWriteProductsScope();
        } catch (\Throwable) {
            return $this->skipped('missing_write_products_scope', $collection['gid'], 0);
        }

        $this->ensureManualSort($collection);
        $gids = $this->orderBuilder->orderedGids($this->listCollectionProducts());
        if ($gids === []) {
            return $this->skipped('no_collection_products', $collection['gid'], 0);
        }

        return $this->sendAndWait($collection['gid'], $gids);
    }

    /**
     * @return array{gid: string, sort_order: string}|null
     */
    private function collection(): ?array
    {
        $handle = (string) config('store_preorder.collection_handle', 'pre-orders');
        $response = $this->client->query(ShopifyAdminGraphQlQueries::COLLECTION_BY_HANDLE, [
            'handle' => $handle,
        ]);
        $node = is_array($response['data']['collectionByHandle'] ?? null)
            ? $response['data']['collectionByHandle']
            : null;
        $gid = is_string($node['id'] ?? null) ? trim($node['id']) : '';
        if ($gid === '') {
            return null;
        }

        $sortOrder = is_string($node['sortOrder'] ?? null) ? strtoupper(trim($node['sortOrder'])) : '';

        return ['gid' => $gid, 'sort_order' => $sortOrder];
    }

    /**
     * @param  array{gid: string, sort_order: string}  $collection
     */
    private function ensureManualSort(array $collection): void
    {
        if ($collection['sort_order'] === 'MANUAL') {
            return;
        }

        $response = $this->client->query(ShopifyAdminGraphQlMutations::COLLECTION_UPDATE, [
            'input' => [
                'id' => $collection['gid'],
                'sortOrder' => 'MANUAL',
            ],
        ]);
        $errors = $response['data']['collectionUpdate']['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            throw new ShopifyGraphQlException($this->userErrorMessage($errors, 'collectionUpdate sortOrder MANUAL failed.'));
        }
    }

    /**
     * @return list<array{gid: string, sku: string}>
     */
    private function listCollectionProducts(): array
    {
        $handle = (string) config('store_preorder.collection_handle', 'pre-orders');
        $after = null;
        $out = [];
        do {
            $response = $this->client->query(ShopifyAdminGraphQlQueries::COLLECTION_PRODUCTS_BY_HANDLE, [
                'handle' => $handle,
                'first' => 50,
                'after' => $after,
            ]);
            $conn = is_array($response['data']['collectionByHandle']['products'] ?? null)
                ? $response['data']['collectionByHandle']['products']
                : [];
            foreach ($conn['nodes'] ?? [] as $node) {
                if (is_array($node)) {
                    $out[] = $this->rowFromNode($node);
                }
            }
            $after = ($conn['pageInfo']['hasNextPage'] ?? false) ? ($conn['pageInfo']['endCursor'] ?? null) : null;
        } while (is_string($after) && $after !== '');

        return $out;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{gid: string, sku: string}
     */
    private function rowFromNode(array $node): array
    {
        $sku = '';
        foreach ($node['variants']['nodes'] ?? [] as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $candidate = trim((string) ($variant['sku'] ?? ''));
            if ($candidate !== '') {
                $sku = $candidate;
                break;
            }
        }

        return ['gid' => trim((string) ($node['id'] ?? '')), 'sku' => $sku];
    }

    /**
     * @param  list<string>  $gids
     */
    private function sendAndWait(string $collectionGid, array $gids): StorePreorderCollectionReorderResult
    {
        $limit = max(1, (int) config('store_preorder.collection_reorder_moves_limit', 250));
        $movesSent = 0;
        $lastJobId = null;
        $jobDone = true;
        foreach (array_chunk($gids, $limit) as $offset => $chunk) {
            $base = $offset * $limit;
            $payload = $this->sendMoves($collectionGid, $chunk, $base);
            $movesSent += count($chunk);
            $lastJobId = $payload['job_id'];
            if ($lastJobId === null) {
                continue;
            }
            $jobDone = $this->asyncJobWait->waitUntilDone(
                $lastJobId,
                (int) config('store_preorder.collection_reorder_job_max_wait_seconds', 120),
                (int) config('store_preorder.collection_reorder_job_poll_ms', 1000),
            );
            if (! $jobDone) {
                break;
            }
        }

        return $this->finish($collectionGid, count($gids), $movesSent, $lastJobId, $jobDone);
    }

    /**
     * @param  list<string>  $gids
     * @return array{job_id: string|null}
     */
    private function sendMoves(string $collectionGid, array $gids, int $base): array
    {
        $moves = [];
        foreach ($gids as $index => $gid) {
            $moves[] = ['id' => $gid, 'newPosition' => (string) ($base + $index)];
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
        $errors = $payload['userErrors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            throw new ShopifyGraphQlException($this->userErrorMessage($errors, 'Shopify collectionReorderProducts returned user errors.'));
        }
        $jobId = is_array($payload['job'] ?? null) && is_string($payload['job']['id'] ?? null)
            ? $payload['job']['id']
            : null;

        return ['job_id' => $jobId];
    }

    private function finish(
        string $collectionGid,
        int $productCount,
        int $movesSent,
        ?string $jobId,
        bool $jobDone,
    ): StorePreorderCollectionReorderResult {
        $timedOut = $jobId !== null && ! $jobDone;
        Log::channel('shopify')->info('shopify.write.store_preorder.collection_reorder.finish', [
            'collection_gid' => $collectionGid,
            'moves_sent' => $movesSent,
            'job_id' => $jobId,
            'job_done' => $jobDone,
            'job_wait_timed_out' => $timedOut,
        ]);

        return new StorePreorderCollectionReorderResult(
            attempted: true,
            collectionGid: $collectionGid,
            productCount: $productCount,
            movesSent: $movesSent,
            jobId: $jobId,
            jobDone: $jobDone,
            jobWaitTimedOut: $timedOut,
            skippedReason: $timedOut ? 'reorder_job_wait_timeout' : null,
        );
    }

    /**
     * @param  list<mixed>  $errors
     */
    private function userErrorMessage(array $errors, string $fallback): string
    {
        $messages = [];
        foreach ($errors as $err) {
            if (is_array($err) && is_string($err['message'] ?? null)) {
                $messages[] = $err['message'];
            }
        }

        return $messages !== [] ? implode('; ', $messages) : $fallback;
    }

    private function skipped(string $reason, ?string $collectionGid, int $productCount): StorePreorderCollectionReorderResult
    {
        Log::channel('shopify')->info('shopify.write.store_preorder.collection_reorder.skipped', [
            'reason' => $reason,
            'collection_gid' => $collectionGid,
        ]);

        return new StorePreorderCollectionReorderResult(
            attempted: false,
            collectionGid: $collectionGid,
            productCount: $productCount,
            movesSent: 0,
            jobId: null,
            jobDone: false,
            jobWaitTimedOut: false,
            skippedReason: $reason,
        );
    }
}
