<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\Models\Product;
use App\Services\StorePreorders\StorePreorderShopifyPushOverride;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use App\Support\Products\Storefront\StorefrontClassification;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Support\Facades\DB;

final class ShopifyProductPushTagsResolver
{
    public function __construct(
        private readonly ProductStorefrontClassifier $classifier,
        private readonly StorePreorderRepository $offers,
    ) {}

    /**
     * Tags to send on productSet, or null when Shopify tags should be left unchanged.
     *
     * @return array<int, string>|null
     */
    public function tagsForProductSet(
        Product $product,
        ?string $productGid,
        bool $isUpdate,
        bool $isInfoPush,
    ): ?array {
        if ($isUpdate && ! $isInfoPush) {
            return null;
        }

        if (StorePreorderShopifyPushOverride::forProduct($product) !== null) {
            return [StorefrontTag::STORE_PREORDER];
        }

        $classification = $this->classifier->classify($product);

        if ($classification->storefrontTags === []) {
            if ($isInfoPush && $classification->shopifyTags !== []) {
                if (! $isUpdate) {
                    return $this->withStorePreorderTag($product, $classification->shopifyTags);
                }

                $existing = $productGid !== null ? $this->existingTags($productGid) : [];

                return $this->withStorePreorderTag($product, $this->mergeTagLists($existing, $classification->shopifyTags));
            }

            if ($this->hasStorePreorderOffer($product)) {
                $existing = $productGid !== null ? $this->existingTags($productGid) : [];

                return $this->mergeTagLists($existing, [StorefrontTag::STORE_PREORDER]);
            }

            return null;
        }

        $existing = $productGid !== null ? $this->existingTags($productGid) : [];

        return $this->withStorePreorderTag($product, $this->mergeForPush($existing, $classification));
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    private function withStorePreorderTag(Product $product, array $tags): array
    {
        if (! $this->hasStorePreorderOffer($product)) {
            return $tags;
        }

        return $this->mergeTagLists($tags, [StorefrontTag::STORE_PREORDER]);
    }

    private function hasStorePreorderOffer(Product $product): bool
    {
        return $this->offers->existsForProductId((int) $product->id);
    }

    /**
     * @return array<int, string>
     */
    private function mergeForPush(array $existing, StorefrontClassification $classification): array
    {
        $legacyKeys = [];
        foreach ($classification->legacyTags as $tag) {
            $legacyKeys[strtolower(trim($tag))] = true;
        }

        $kept = [];
        foreach ($existing as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '' || isset($legacyKeys[strtolower($tag)])) {
                continue;
            }

            if (str_starts_with(strtolower($tag), 'mk:')) {
                continue;
            }

            if (str_starts_with(strtolower($tag), 'misc:')) {
                continue;
            }

            $kept[] = $tag;
        }

        return $this->mergeTagLists($kept, $classification->shopifyTags);
    }

    /**
     * @return array<int, string>
     */
    private function existingTags(string $productGid): array
    {
        $raw = DB::table('shopify_products')
            ->where('gid', $productGid)
            ->value('payload_json');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return [];
        }

        $tags = $payload['tags'] ?? [];
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $tag): string => trim((string) $tag),
            $tags,
        ), static fn (string $tag): bool => $tag !== ''));
    }

    /**
     * @param  array<int, string>  $base
     * @param  array<int, string>  $additional
     * @return array<int, string>
     */
    private function mergeTagLists(array $base, array $additional): array
    {
        $out = [];
        $seen = [];

        foreach ([...$base, ...$additional] as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }

            $key = strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $tag;
        }

        return $out;
    }
}
