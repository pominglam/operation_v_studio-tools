<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Models\Product;
use App\Support\Products\Storefront\ProductStorefrontClassifier;
use App\Support\Products\Storefront\StorefrontClassification;
use Illuminate\Support\Facades\DB;

final class ShopifyProductPushTagsResolver
{
    public function __construct(
        private readonly ProductStorefrontClassifier $classifier,
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
        $classification = $this->classifier->classify($product);

        if ($classification->storefrontTags === []) {
            if (! $isUpdate && $isInfoPush && $classification->shopifyTags !== []) {
                return $classification->shopifyTags;
            }

            return null;
        }

        $existing = $productGid !== null ? $this->existingTags($productGid) : [];

        return $this->mergeForPush($existing, $classification);
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
