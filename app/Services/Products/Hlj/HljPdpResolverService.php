<?php

declare(strict_types=1);

namespace App\Services\Products\Hlj;

use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;

final class HljPdpResolverService
{
    public const string SOURCE = 'hlj';

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly HljHtmlParser $parser,
    ) {}

    public function resolvePdpUrlForProduct(Product $product): ?string
    {
        $candidates = [];

        $barcode = is_string($product->barcode) ? trim($product->barcode) : '';
        if ($barcode !== '') $candidates[] = $barcode;

        $sku = is_string($product->sku) ? trim($product->sku) : '';
        if ($sku !== '') $candidates[] = $sku;

        $name = is_string($product->description) ? trim($product->description) : '';
        if ($name !== '') $candidates[] = $name;

        $candidates = array_values(array_unique(array_filter($candidates)));
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $q) {
            $url = $this->resolvePdpUrlForQuery($q);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public function resolvePdpUrlForQuery(string $query): ?string
    {
        $q = rawurlencode(trim($query));
        if ($q === '') return null;

        $searchUrl = "https://www.hlj.com/search/?q={$q}";
        $res = $this->http->get($searchUrl, siteKey: self::SOURCE);
        if (! $res->successful()) {
            return null;
        }

        return $this->parser->extractPdpUrlFromSearchHtml((string) $res->body());
    }
}


