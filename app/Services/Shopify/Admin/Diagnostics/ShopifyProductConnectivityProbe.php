<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Diagnostics;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;
use InvalidArgumentException;

/**
 * Read-only: fetches {@see ShopifyAdminGraphQlQueries::PRODUCTS_CONNECTIVITY_PREVIEW}.
 * Persists nothing to local ERP/product tables.
 */
final class ShopifyProductConnectivityProbe
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $graphQlClient,
    ) {}

    /**
     * Clamped upstream (command); expect 1–50.
     *
     * @return list<array{gid:string,title:string,handle:string,status:string,vendor:string,product_type:string,variant_count_display:string}>
     */
    public function previewProducts(int $first): array
    {
        if ($first < 1 || $first > 50) {
            throw new InvalidArgumentException('Preview limit must be between 1 and 50.');
        }

        /** @var array<string, mixed> $resp */
        $resp = $this->graphQlClient->query(ShopifyAdminGraphQlQueries::PRODUCTS_CONNECTIVITY_PREVIEW, [
            'first' => $first,
        ]);

        /** @var array<string, mixed>|null $wrapper */
        $wrapper = isset($resp['data']['products']) && is_array($resp['data']['products'])
            ? $resp['data']['products']
            : null;
        if ($wrapper === null) {
            throw new ShopifyGraphQlException('Connectivity preview missing data.products.');
        }

        /** @var list<mixed> $nodes */
        $nodes = $wrapper['nodes'] ?? [];
        if (! is_array($nodes)) {
            throw new ShopifyGraphQlException('Connectivity preview missing products.nodes.');
        }

        /** @var list<array{gid:string,title:string,handle:string,status:string,vendor:string,product_type:string,variant_count_display:string}> $out */
        $out = [];

        foreach ($nodes as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            /** @var string $gid */
            $gid = isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : '';
            if ($gid === '') {
                continue;
            }

            /** @var array<string, mixed> $variants */
            $variants = isset($raw['variants']) && is_array($raw['variants']) ? $raw['variants'] : [];
            /** @var list<mixed> $vNodes */
            $vNodes = isset($variants['nodes']) && is_array($variants['nodes']) ? $variants['nodes'] : [];
            $count = count($vNodes);

            /** @var array<string, mixed>|null $pageInfo */
            $pageInfo = isset($variants['pageInfo']) && is_array($variants['pageInfo']) ? $variants['pageInfo'] : null;

            $hasMore = isset($pageInfo['hasNextPage']) && $pageInfo['hasNextPage'] === true;

            $variantDisplay = ($hasMore && $count > 0)
                ? $count.'+'
                : (string) $count;

            $out[] = [
                'gid' => $gid,
                'title' => isset($raw['title']) && is_string($raw['title']) ? $raw['title'] : '',
                'handle' => isset($raw['handle']) && is_string($raw['handle']) ? $raw['handle'] : '',
                'status' => isset($raw['status']) && is_string($raw['status']) ? $raw['status'] : '',
                'vendor' => isset($raw['vendor']) && is_string($raw['vendor']) ? $raw['vendor'] : '',
                'product_type' => isset($raw['productType']) && is_string($raw['productType']) ? $raw['productType'] : '',
                'variant_count_display' => $variantDisplay,
            ];
        }

        return $out;
    }
}
