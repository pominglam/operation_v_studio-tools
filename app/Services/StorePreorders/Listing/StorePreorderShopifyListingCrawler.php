<?php

declare(strict_types=1);

namespace App\Services\StorePreorders\Listing;

use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\StorePreorders\Exceptions\StorePreorderListingCrawlException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class StorePreorderShopifyListingCrawler implements StorePreorderListingCrawler
{
    /** @var list<string> */
    private const HOSTS = [
        'fuwafuwaland.ca',
    ];

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly StorePreorderListingEtaParser $etas,
    ) {}

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        return self::HOSTS;
    }

    public function crawl(string $url): StorePreorderListingCrawlResult
    {
        $jsUrl = $this->productJsUrl($url);
        $host = StorePreorderListingCrawlerRegistry::hostFromUrl($url);
        try {
            $response = $this->http->get(
                $jsUrl,
                ['Accept' => 'application/json, text/javascript, */*'],
                siteKey: 'store_preorder_listing_'.$host,
            );
        } catch (ConnectionException|Throwable $exception) {
            throw StorePreorderListingCrawlException::failed(
                'Could not fetch the product page: '.$exception->getMessage(),
                $host,
            );
        }

        if (! $response->successful()) {
            throw StorePreorderListingCrawlException::failed(
                'Product page returned HTTP '.$response->status().'.',
                $host,
            );
        }

        return $this->fromJson($url, $host, $response->json());
    }

    private function productJsUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';
        if ($host === '' || ! preg_match('#/products/[^/]+#', $path)) {
            throw StorePreorderListingCrawlException::failed(
                'URL must be a product page (/products/…).',
                StorePreorderListingCrawlerRegistry::normalizeHost($host),
            );
        }

        $path = preg_replace('#\.js$#', '', rtrim($path, '/')) ?? $path;

        return $scheme.'://'.$host.$path.'.js';
    }

    private function fromJson(string $url, string $host, mixed $json): StorePreorderListingCrawlResult
    {
        if (! is_array($json)) {
            throw StorePreorderListingCrawlException::failed('Product page was not Shopify JSON.', $host);
        }

        $title = trim((string) ($json['title'] ?? ''));
        if ($title === '') {
            throw StorePreorderListingCrawlException::failed('Product page has no title.', $host);
        }

        $body = $this->nullableHtml($json['body_html'] ?? null)
            ?? $this->nullableHtml($json['description'] ?? null);
        $eta = $this->etas->fromText($title.' '.strip_tags((string) ($body ?? '')));

        return new StorePreorderListingCrawlResult(
            title: $title,
            descriptionHtml: $body,
            etaDate: $eta,
            retailPriceCad: $this->priceCad($json),
            imageUrls: $this->imageUrls($json),
            sourceUrl: $url,
        );
    }

    private function nullableHtml(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $html = trim($value);

        return $html === '' ? null : $html;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function priceCad(array $json): ?string
    {
        $cents = $json['price'] ?? null;
        if (! is_numeric($cents)) {
            return null;
        }
        $amount = ((int) $cents) / 100;
        if ($amount < 0.01) {
            return null;
        }

        return number_format($amount, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private function imageUrls(array $json): array
    {
        $images = $json['images'] ?? [];
        if (! is_array($images)) {
            return [];
        }

        $urls = [];
        $featured = $json['featured_image'] ?? null;
        if (is_string($featured) && trim($featured) !== '') {
            $urls[] = str_starts_with(trim($featured), '//') ? 'https:'.trim($featured) : trim($featured);
        }
        foreach ($images as $image) {
            $src = is_array($image) ? ($image['src'] ?? null) : $image;
            if (! is_string($src) || trim($src) === '') {
                continue;
            }
            $src = trim($src);
            if (str_starts_with($src, '//')) {
                $src = 'https:'.$src;
            }
            $urls[] = $src;
        }

        return array_values(array_unique($urls));
    }
}
