<?php

declare(strict_types=1);

namespace App\Services\StorePreorders\Listing;

final class StorePreorderListingCrawlerRegistry
{
    /** @var array<string, StorePreorderListingCrawler> */
    private array $byHost = [];

    /**
     * @param  iterable<StorePreorderListingCrawler>  $crawlers
     */
    public function __construct(iterable $crawlers)
    {
        foreach ($crawlers as $crawler) {
            foreach ($crawler->hosts() as $host) {
                $normalized = self::normalizeHost($host);
                if ($normalized !== '') {
                    $this->byHost[$normalized] = $crawler;
                }
            }
        }
    }

    public function forUrl(string $url): ?StorePreorderListingCrawler
    {
        $host = self::hostFromUrl($url);
        if ($host === '') {
            return null;
        }

        return $this->byHost[$host] ?? null;
    }

    public static function hostFromUrl(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return self::normalizeHost(is_string($host) ? $host : '');
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }

        return $host;
    }
}
