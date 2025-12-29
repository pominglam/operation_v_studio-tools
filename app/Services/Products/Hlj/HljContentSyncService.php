<?php

declare(strict_types=1);

namespace App\Services\Products\Hlj;

use App\DAL\Products\ProductExternalContentRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;

final class HljContentSyncService
    implements HljContentSync
{
    public const string SOURCE = 'hlj';

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly HljHtmlParser $parser,
        private readonly ProductExternalContentRepository $contents,
    ) {}

    public function syncForProduct(Product $product): void
    {
        $candidates = [];

        $barcode = is_string($product->barcode) ? trim($product->barcode) : '';
        if ($barcode !== '') $candidates[] = $barcode;

        $sku = is_string($product->sku) ? trim($product->sku) : '';
        if ($sku !== '') $candidates[] = $sku;

        // In this project, `description` is the product name.
        $name = is_string($product->description) ? trim($product->description) : '';
        if ($name !== '') $candidates[] = $name;

        $candidates = array_values(array_unique(array_filter($candidates)));
        if ($candidates === []) return;

        $pdpUrl = null;
        foreach ($candidates as $q) {
            $pdpUrl = $this->findPdpUrlByQuery($q);
            if ($pdpUrl !== null) break;
        }
        if ($pdpUrl === null) return;

        $pdpRes = $this->http->get($pdpUrl, siteKey: self::SOURCE);
        if (! $pdpRes->successful()) {
            return;
        }

        $parsed = $this->parser->extractTitleAndDescription((string) $pdpRes->body());
        if (($parsed['title'] ?? null) === null && ($parsed['description_html'] ?? null) === null) {
            return;
        }

        $this->contents->upsertForProduct(
            productId: (int) $product->id,
            source: self::SOURCE,
            title: $parsed['title'] ?? null,
            descriptionHtml: $parsed['description_html'] ?? null,
            attributes: null,
            sourceUrl: $pdpUrl,
        );
    }

    private function findPdpUrlByQuery(string $query): ?string
    {
        $q = rawurlencode($query);
        $searchUrl = "https://www.hlj.com/search/?q={$q}";

        $res = $this->http->get($searchUrl, siteKey: self::SOURCE);
        if (! $res->successful()) {
            return null;
        }

        return $this->parser->extractPdpUrlFromSearchHtml((string) $res->body());
    }
}


