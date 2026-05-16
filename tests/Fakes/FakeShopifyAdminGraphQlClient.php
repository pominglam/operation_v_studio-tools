<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use RuntimeException;

final class FakeShopifyAdminGraphQlClient implements ShopifyAdminGraphQlClientInterface
{
    /** @var list<array<string, mixed>> */
    private array $responses = [];

    public function queueResponse(array $json): void
    {
        $this->responses[] = $json;
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<string, mixed>
     */
    public static function wrapLocations(array $nodes, bool $hasNextPage, ?string $endCursor): array
    {
        return [
            'data' => [
                'locations' => [
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => $endCursor,
                    ],
                    'nodes' => $nodes,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $products
     * @return array<string, mixed>
     */
    public static function wrapProducts(array $products, bool $hasNextPage, ?string $endCursor): array
    {
        return [
            'data' => [
                'products' => [
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => $endCursor,
                    ],
                    'nodes' => $products,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, mixed>|null  $inventoryLevelsConn
     * @return array<string, mixed>
     */
    public static function wrapInventoryItem(?string $itemId, mixed $inventoryLevelsConn): array
    {
        if ($itemId === null || $inventoryLevelsConn === null) {
            return [
                'data' => [
                    'inventoryItem' => null,
                ],
            ];
        }

        return [
            'data' => [
                'inventoryItem' => [
                    'id' => $itemId,
                    'inventoryLevels' => $inventoryLevelsConn,
                ],
            ],
        ];
    }

    public function query(string $graphql, array $variables = []): array
    {
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new RuntimeException('FakeShopifyAdminGraphQlClient ran out of queued responses.');
        }

        return $next;
    }
}
