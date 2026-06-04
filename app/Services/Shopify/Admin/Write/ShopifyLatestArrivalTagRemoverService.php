<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Models\Product;
use App\Services\Products\ProductExportService;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ShopifyLatestArrivalTagRemoverService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
    ) {}

    /**
     * @param  Collection<int, Product>  $products
     */
    public function assertCanRemoveForProducts(Collection $products): void
    {
        if ($this->productsWithShopifyGid($products) === []) {
            return;
        }

        $this->scopeGuard->assertWriteProductsScope();
    }

    /**
     * Removes only the {@see ProductExportService::LATEST_ARRIVAL_TAG} tag on Shopify; does not change other tags or product fields.
     *
     * @param  Collection<int, Product>  $products
     * @return array{
     *   shopify_tags_removed: int,
     *   shopify_skipped_no_gid: int,
     *   shopify_tag_removals_failed: int
     * }
     */
    public function removeFromProducts(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [
                'shopify_tags_removed' => 0,
                'shopify_skipped_no_gid' => 0,
                'shopify_tag_removals_failed' => 0,
            ];
        }

        $this->assertCanRemoveForProducts($products);

        $removed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($products as $product) {
            $gid = $this->resolveProductGidBySku((string) $product->sku);
            if ($gid === null) {
                $skipped++;

                continue;
            }

            try {
                $this->removeTagFromProductGid($gid, (string) $product->sku);
                $removed++;
            } catch (ShopifyGraphQlException) {
                $failed++;
            }
        }

        return [
            'shopify_tags_removed' => $removed,
            'shopify_skipped_no_gid' => $skipped,
            'shopify_tag_removals_failed' => $failed,
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<string>
     */
    private function productsWithShopifyGid(Collection $products): array
    {
        $gids = [];
        foreach ($products as $product) {
            $gid = $this->resolveProductGidBySku((string) $product->sku);
            if ($gid !== null) {
                $gids[] = $gid;
            }
        }

        return $gids;
    }

    private function resolveProductGidBySku(string $sku): ?string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $gid = DB::table('shopify_product_variants')
            ->where('sku', '=', $sku)
            ->value('product_gid');

        if (! is_string($gid) || trim($gid) === '') {
            return null;
        }

        return $gid;
    }

    private function removeTagFromProductGid(string $productGid, string $sku): void
    {
        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.tags_remove.start', [
            'sku' => $sku,
            'product_gid' => $productGid,
            'tag' => ProductExportService::LATEST_ARRIVAL_TAG,
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::TAGS_REMOVE, [
            'id' => $productGid,
            'tags' => [ProductExportService::LATEST_ARRIVAL_TAG],
        ]);

        Log::channel('shopify')->info('shopify.write.tags_remove.finish', [
            'sku' => $sku,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $payload = is_array($response['data']['tagsRemove'] ?? null) ? $response['data']['tagsRemove'] : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify tagsRemove returned no payload.');
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
                $messages !== [] ? implode('; ', $messages) : 'Shopify tagsRemove returned user errors.',
            );
        }
    }
}
