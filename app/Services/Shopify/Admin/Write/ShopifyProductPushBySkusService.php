<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\Products\ProductRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Models\Product;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlQueries;

final class ShopifyProductPushBySkusService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ShopifyProductUpsertFromErpService $upsert,
        private readonly ShopifyInventoryLocationResolver $locationResolver,
        private readonly ShopifyAdminGraphQlClientInterface $client,
    ) {}

    /**
     * @param  list<string>  $skus
     * @return array<int, array{sku: string, action: string, shopify_gid: string, tags: string}>
     */
    public function push(array $skus, ?ShopifyProductPushOptionsDTO $options = null): array
    {
        $options ??= new ShopifyProductPushOptionsDTO(
            info: true,
            images: false,
            quantities: true,
            price: true,
            publishStatus: true,
            salesChannels: true,
        );

        $locationGid = $this->locationResolver->resolveLocationGid();
        $usedHandles = [];
        $rows = [];

        foreach ($skus as $sku) {
            $sku = strtoupper(trim($sku));
            if ($sku === '') {
                continue;
            }

            $product = Product::query()->where('sku', $sku)->first();
            if ($product === null) {
                $rows[] = [
                    'sku' => $sku,
                    'action' => 'error',
                    'shopify_gid' => '-',
                    'tags' => 'ERP row not found',
                ];

                continue;
            }

            $loaded = $this->products->listForShopifyContentExportByUuids([(string) $product->uuid])->first();
            if ($loaded === null) {
                $rows[] = [
                    'sku' => $sku,
                    'action' => 'error',
                    'shopify_gid' => '-',
                    'tags' => 'Export row not found',
                ];

                continue;
            }

            try {
                $result = $this->upsert->upsertFromProduct($loaded, null, $locationGid, $usedHandles, $options);
                $verifiedTags = $this->verifyProductTags((string) $result['shopify_gid']);
                $rows[] = [
                    'sku' => $sku,
                    'action' => (string) $result['action'],
                    'shopify_gid' => (string) $result['shopify_gid'],
                    'tags' => $verifiedTags !== null ? implode(', ', $verifiedTags) : 'VERIFY_FAILED',
                ];
            } catch (\Throwable $e) {
                $rows[] = [
                    'sku' => $sku,
                    'action' => 'error',
                    'shopify_gid' => '-',
                    'tags' => $e->getMessage(),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>|null
     */
    private function verifyProductTags(string $productGid): ?array
    {
        $response = $this->client->query(ShopifyAdminGraphQlQueries::PRODUCT_TAGS_BY_ID, [
            'id' => $productGid,
        ]);
        $node = is_array($response['data']['product'] ?? null) ? $response['data']['product'] : null;
        if ($node === null) {
            return null;
        }

        $tags = is_array($node['tags'] ?? null) ? $node['tags'] : [];

        return array_values(array_filter(array_map('strval', $tags), static fn (string $tag): bool => trim($tag) !== ''));
    }
}
