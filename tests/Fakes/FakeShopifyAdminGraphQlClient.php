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

    /**
     * @param  array<int, mixed>  $orders
     * @return array<string, mixed>
     */
    public static function wrapOrders(array $orders, bool $hasNextPage, ?string $endCursor): array
    {
        return [
            'data' => [
                'orders' => [
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => $endCursor,
                    ],
                    'nodes' => $orders,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function wrapOrderById(?array $order): array
    {
        return [
            'data' => [
                'order' => $order,
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

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<int, string>  $publicationIds
     * @return array<string, mixed>
     */
    public static function wrapPublications(array $publicationIds): array
    {
        $nodes = [];
        foreach ($publicationIds as $id) {
            $nodes[] = ['id' => $id, 'name' => 'Channel'];
        }

        return [
            'data' => [
                'publications' => [
                    'pageInfo' => [
                        'hasNextPage' => false,
                        'endCursor' => null,
                    ],
                    'nodes' => $nodes,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function wrapPublishablePublish(): array
    {
        return [
            'data' => [
                'publishablePublish' => [
                    'publishable' => [
                        'resourcePublicationsCount' => [
                            'count' => 3,
                        ],
                    ],
                    'userErrors' => [],
                ],
            ],
        ];
    }

    public static function wrapProductSet(string $gid, string $handle): array
    {
        return [
            'data' => [
                'productSet' => [
                    'product' => [
                        'id' => $gid,
                        'handle' => $handle,
                    ],
                    'userErrors' => [],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $mediaIds
     * @return array<string, mixed>
     */
    public static function wrapProductMediaIds(array $mediaIds, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        $nodes = [];
        foreach ($mediaIds as $id) {
            $nodes[] = ['id' => $id];
        }

        return [
            'data' => [
                'product' => [
                    'id' => 'gid://shopify/Product/1',
                    'media' => [
                        'pageInfo' => [
                            'hasNextPage' => $hasNextPage,
                            'endCursor' => $endCursor,
                        ],
                        'nodes' => $nodes,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array{id?: string, status?: string, mediaContentType?: string, mediaErrors?: array<int, array<string, mixed>>}>  $nodes
     * @return array<string, mixed>
     */
    public static function wrapProductMediaStatus(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): array
    {
        return [
            'data' => [
                'product' => [
                    'id' => 'gid://shopify/Product/1',
                    'media' => [
                        'pageInfo' => [
                            'hasNextPage' => $hasNextPage,
                            'endCursor' => $endCursor,
                        ],
                        'nodes' => $nodes,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $deletedMediaIds
     * @return array<string, mixed>
     */
    public static function wrapProductDeleteMedia(array $deletedMediaIds): array
    {
        return [
            'data' => [
                'productDeleteMedia' => [
                    'deletedMediaIds' => $deletedMediaIds,
                    'mediaUserErrors' => [],
                    'userErrors' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function wrapTagsRemove(string $nodeId): array
    {
        return [
            'data' => [
                'tagsRemove' => [
                    'node' => [
                        'id' => $nodeId,
                    ],
                    'userErrors' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function wrapInventorySetQuantities(): array
    {
        return [
            'data' => [
                'inventorySetQuantities' => [
                    'inventoryAdjustmentGroup' => [
                        'reason' => 'correction',
                    ],
                    'userErrors' => [],
                ],
            ],
        ];
    }
}
