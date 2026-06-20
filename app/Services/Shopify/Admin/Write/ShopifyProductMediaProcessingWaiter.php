<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use Illuminate\Support\Facades\Log;

final class ShopifyProductMediaProcessingWaiter
{
    private const int MAX_WAIT_SECONDS = 120;

    private const int POLL_INTERVAL_MS = 500;

    private const int MEDIA_PAGE_SIZE = 50;

    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
    ) {}

    public function waitForReady(string $productGid, int $expectedImageCount): void
    {
        $productGid = trim($productGid);
        if ($expectedImageCount <= 0 || $productGid === '') {
            return;
        }

        $startedAt = microtime(true);
        $deadline = $startedAt + self::MAX_WAIT_SECONDS;

        Log::channel('shopify')->info('shopify.write.media_wait.start', [
            'product_gid' => $productGid,
            'expected_images' => $expectedImageCount,
        ]);

        while (microtime(true) < $deadline) {
            $images = $this->fetchImageMediaStatuses($productGid);

            foreach ($images as $image) {
                if ($image['status'] === 'FAILED') {
                    throw new ShopifyGraphQlException($this->formatMediaFailure($image));
                }
            }

            $readyCount = count(array_filter(
                $images,
                static fn (array $image): bool => $image['status'] === 'READY',
            ));

            if ($readyCount >= $expectedImageCount) {
                Log::channel('shopify')->info('shopify.write.media_wait.finish', [
                    'product_gid' => $productGid,
                    'ready_count' => $readyCount,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);

                return;
            }

            usleep(self::POLL_INTERVAL_MS * 1000);
        }

        throw new ShopifyGraphQlException(sprintf(
            'Timed out after %d seconds waiting for %d Shopify product image(s) to become READY.',
            self::MAX_WAIT_SECONDS,
            $expectedImageCount,
        ));
    }

    /**
     * @return array<int, array{id: string, status: string, errors: array<int, array{code: string, message: string, details: string}>}>
     */
    private function fetchImageMediaStatuses(string $productGid): array
    {
        $images = [];
        $after = null;

        do {
            $response = $this->client->query(ShopifyAdminGraphQlQueries::PRODUCT_MEDIA_STATUS, [
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

                $contentType = is_string($node['mediaContentType'] ?? null) ? $node['mediaContentType'] : '';
                if ($contentType !== '' && strtoupper($contentType) !== 'IMAGE') {
                    continue;
                }

                $id = is_string($node['id'] ?? null) ? trim($node['id']) : '';
                $status = is_string($node['status'] ?? null) ? strtoupper(trim($node['status'])) : '';
                if ($id === '' || $status === '') {
                    continue;
                }

                $images[] = [
                    'id' => $id,
                    'status' => $status,
                    'errors' => $this->normalizeMediaErrors($node),
                ];
            }

            $pageInfo = is_array($media['pageInfo'] ?? null) ? $media['pageInfo'] : [];
            $hasNext = ($pageInfo['hasNextPage'] ?? false) === true;
            $after = $hasNext && is_string($pageInfo['endCursor'] ?? null) ? $pageInfo['endCursor'] : null;
        } while ($after !== null);

        return $images;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<int, array{code: string, message: string, details: string}>
     */
    private function normalizeMediaErrors(array $node): array
    {
        $errors = [];
        $rawErrors = is_array($node['mediaErrors'] ?? null) ? $node['mediaErrors'] : [];
        foreach ($rawErrors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $errors[] = [
                'code' => is_string($error['code'] ?? null) ? trim($error['code']) : '',
                'message' => is_string($error['message'] ?? null) ? trim($error['message']) : '',
                'details' => is_string($error['details'] ?? null) ? trim($error['details']) : '',
            ];
        }

        return $errors;
    }

    /**
     * @param  array{id: string, status: string, errors: array<int, array{code: string, message: string, details: string}>}  $image
     */
    private function formatMediaFailure(array $image): string
    {
        $parts = [sprintf('Shopify media %s failed to process.', $image['id'])];
        foreach ($image['errors'] as $error) {
            $detail = $error['details'] !== '' ? $error['details'] : $error['message'];
            if ($detail !== '') {
                $parts[] = $detail;
            }
        }

        return implode(' ', $parts);
    }
}
