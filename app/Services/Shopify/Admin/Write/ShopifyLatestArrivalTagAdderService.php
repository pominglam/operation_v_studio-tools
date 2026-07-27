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

final class ShopifyLatestArrivalTagAdderService
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
    ) {}

    /**
     * @param  Collection<int, Product>  $products
     */
    public function assertCanAddForProducts(Collection $products): void
    {
        if ($this->productsWithShopifyGid($products) === []) {
            return;
        }

        $this->scopeGuard->assertWriteProductsScope();
    }

    /**
     * Adds only the {@see ProductExportService::LATEST_ARRIVAL_TAG} tag on Shopify; does not change other tags or product fields.
     *
     * @param  Collection<int, Product>  $products
     * @return array{
     *   shopify_tags_added: int,
     *   shopify_skipped_no_gid: int,
     *   shopify_skipped_already_tagged: int,
     *   shopify_tag_additions_failed: int
     * }
     */
    public function addToProducts(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [
                'shopify_tags_added' => 0,
                'shopify_skipped_no_gid' => 0,
                'shopify_skipped_already_tagged' => 0,
                'shopify_tag_additions_failed' => 0,
            ];
        }

        $this->assertCanAddForProducts($products);

        $added = 0;
        $skippedNoGid = 0;
        $skippedAlreadyTagged = 0;
        $failed = 0;

        foreach ($products as $product) {
            if (! $product->latest_arrival) {
                continue;
            }

            $gid = $this->resolveProductGidBySku((string) $product->sku);
            if ($gid === null) {
                $skippedNoGid++;

                continue;
            }

            if ($this->mirrorHasLatestArrivalTag($gid)) {
                $skippedAlreadyTagged++;

                continue;
            }

            try {
                $this->addTagToProductGid($gid, (string) $product->sku);
                $added++;
            } catch (ShopifyGraphQlException) {
                $failed++;
            }
        }

        return [
            'shopify_tags_added' => $added,
            'shopify_skipped_no_gid' => $skippedNoGid,
            'shopify_skipped_already_tagged' => $skippedAlreadyTagged,
            'shopify_tag_additions_failed' => $failed,
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

    private function mirrorHasLatestArrivalTag(string $productGid): bool
    {
        $raw = DB::table('shopify_products')
            ->where('gid', $productGid)
            ->value('payload_json');

        if (! is_string($raw) || trim($raw) === '') {
            return false;
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return false;
        }

        $tags = $payload['tags'] ?? [];
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }

        if (! is_array($tags)) {
            return false;
        }

        $needle = strtolower(ProductExportService::LATEST_ARRIVAL_TAG);
        foreach ($tags as $tag) {
            if (strtolower(trim((string) $tag)) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function addTagToProductGid(string $productGid, string $sku): void
    {
        $startedAt = microtime(true);
        Log::channel('shopify')->info('shopify.write.tags_add.start', [
            'sku' => $sku,
            'product_gid' => $productGid,
            'tag' => ProductExportService::LATEST_ARRIVAL_TAG,
        ]);

        $response = $this->client->query(ShopifyAdminGraphQlMutations::TAGS_ADD, [
            'id' => $productGid,
            'tags' => [ProductExportService::LATEST_ARRIVAL_TAG],
        ]);

        Log::channel('shopify')->info('shopify.write.tags_add.finish', [
            'sku' => $sku,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $payload = is_array($response['data']['tagsAdd'] ?? null) ? $response['data']['tagsAdd'] : null;
        if ($payload === null) {
            throw new ShopifyGraphQlException('Shopify tagsAdd returned no payload.');
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
                $messages !== [] ? implode('; ', $messages) : 'Shopify tagsAdd returned user errors.',
            );
        }
    }
}
