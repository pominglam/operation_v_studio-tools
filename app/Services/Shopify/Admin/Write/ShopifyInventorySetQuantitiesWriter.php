<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use Illuminate\Support\Facades\Log;

final class ShopifyInventorySetQuantitiesWriter
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
    ) {}

    /**
     * @param  list<array{inventory_item_gid: string, quantity: int}>  $lines
     * @return array{pushed: int, failed: int, errors: list<array{inventory_item_gid: string, message: string}>}
     */
    public function setAvailableAtLocation(string $locationGid, array $lines): array
    {
        $this->scopeGuard->assertWriteInventoryScope();

        if ($lines === []) {
            return ['pushed' => 0, 'failed' => 0, 'errors' => []];
        }

        $batchSize = max(1, min(250, (int) config('shopify.inventory_set_batch_size', 100)));
        $pushed = 0;
        $failed = 0;
        $errors = [];

        foreach (array_chunk($lines, $batchSize) as $chunk) {
            try {
                $this->pushChunk($locationGid, $chunk);
                $pushed += count($chunk);
            } catch (ShopifyGraphQlException $e) {
                $failed += count($chunk);
                foreach ($chunk as $line) {
                    $errors[] = [
                        'inventory_item_gid' => $line['inventory_item_gid'],
                        'message' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'pushed' => $pushed,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<array{inventory_item_gid: string, quantity: int}>  $chunk
     */
    private function pushChunk(string $locationGid, array $chunk): void
    {
        $quantities = [];
        foreach ($chunk as $line) {
            $itemGid = trim($line['inventory_item_gid']);
            if ($itemGid === '') {
                continue;
            }
            $quantities[] = [
                'inventoryItemId' => $itemGid,
                'locationId' => $locationGid,
                'quantity' => max(0, (int) $line['quantity']),
            ];
        }

        if ($quantities === []) {
            return;
        }

        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.inventory_set_quantities.start', [
            'location_gid' => $locationGid,
            'lines' => count($quantities),
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::INVENTORY_SET_QUANTITIES, [
            'input' => [
                'name' => 'available',
                'reason' => 'correction',
                'ignoreCompareQuantity' => true,
                'quantities' => $quantities,
            ],
        ]);

        Log::channel('shopify')->info('shopify.write.inventory_set_quantities.finish', [
            'location_gid' => $locationGid,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $payload = is_array($response['data']['inventorySetQuantities'] ?? null)
            ? $response['data']['inventorySetQuantities']
            : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify inventorySetQuantities returned no payload.');
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
                $messages !== [] ? implode('; ', $messages) : 'Shopify inventorySetQuantities returned user errors.',
            );
        }
    }
}
