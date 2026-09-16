<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use Illuminate\Support\Facades\DB;

final class ModelKitStorefrontIndexImageResolver
{
    /**
     * @param  array<int, string>  $skus
     * @return array<string, string> SKU → Shopify CDN image URL
     */
    public function urlsBySku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus, static fn (string $sku): bool => $sku !== '')));
        if ($skus === []) {
            return [];
        }

        $rows = DB::table('shopify_product_variants as v')
            ->join('shopify_products as p', 'p.gid', '=', 'v.product_gid')
            ->where('p.status', '=', 'ACTIVE')
            ->whereIn('v.sku', $skus)
            ->get(['v.sku', 'p.payload_json']);

        $urls = [];
        foreach ($rows as $row) {
            $sku = trim((string) $row->sku);
            if ($sku === '' || isset($urls[$sku])) {
                continue;
            }
            $url = $this->urlFromPayload($row->payload_json);
            if ($url !== '') {
                $urls[$sku] = $url;
            }
        }

        return $urls;
    }

    public function urlFromPayload(mixed $payload): string
    {
        $decoded = $this->decodePayload($payload);
        if ($decoded === []) {
            return '';
        }

        $candidates = [
            $decoded['featuredImage']['url'] ?? null,
            $decoded['featuredMedia']['preview']['image']['url'] ?? null,
            $decoded['featured_image']['src'] ?? null,
            $decoded['images'][0]['src'] ?? null,
            $decoded['images'][0]['url'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && str_starts_with($candidate, 'https://')) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || $payload === '') {
            return [];
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
