<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Http;

use Illuminate\Support\Facades\Http;

final class AliExpressScraperClient
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @param  array{term: string, sku?: string|null, barcode?: string|null}  $payload
     * @return array<string, mixed>
     */
    public function searchAndScrape(array $payload): array
    {
        $res = Http::timeout(35)
            ->connectTimeout(5)
            ->post(rtrim($this->baseUrl, '/').'/search-and-scrape', $payload);

        /** @var array<string, mixed> $json */
        $json = $res->json() ?? [];
        if (! $res->successful()) {
            $msg = (string) ($json['error_message'] ?? $json['message'] ?? 'AliExpress scraper error');
            return [
                'status' => 'error',
                'error_message' => $msg,
            ];
        }

        return $json;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cookies
     */
    public function setCookies(array $cookies): bool
    {
        $res = Http::timeout(15)
            ->connectTimeout(5)
            ->post(rtrim($this->baseUrl, '/').'/cookies', [
                'cookies' => $cookies,
            ]);

        return $res->successful();
    }
}


