<?php

declare(strict_types=1);

namespace App\Services\Products\Http;

use Illuminate\Support\Facades\Http;

final class PlamodScraperClient implements PlamodScraper
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @return array{ok: bool, error_message?: string, sku?: string, pdp_url?: string, zip_storage_path?: string, metadata?: array<string, mixed>}
     */
    public function downloadZip(string $sku): array
    {
        $url = rtrim($this->baseUrl, '/').'/download-zip';

        // Plamod login + download can be slow (Playwright navigation + download event).
        $res = Http::timeout(230)
            ->connectTimeout(10)
            ->retry(3, 250, function ($exception): bool {
                // Retry timeouts / connection resets (no business logic here).
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
            ->post($url, ['sku' => $sku]);

        /** @var array<string, mixed> $json */
        $json = $res->json() ?? [];

        if (! $res->successful()) {
            $msg = (string) ($json['error_message'] ?? $json['message'] ?? 'Plamod scraper error');

            return [
                'ok' => false,
                'error_message' => $msg,
                'debug' => $json['debug'] ?? null,
            ];
        }

        return $json + ['ok' => true];
    }

    /**
     * @return array{ok: bool, error_message?: string, csv_storage_path?: string, bytes?: int, duration_ms?: int}
     */
    public function exportPreordersCsv(): array
    {
        $url = rtrim($this->baseUrl, '/').'/export-preorders-csv';

        $res = Http::timeout(230)
            ->connectTimeout(10)
            ->retry(3, 250, function ($exception): bool {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
            ->post($url);

        /** @var array<string, mixed> $json */
        $json = $res->json() ?? [];

        if (! $res->successful()) {
            $msg = (string) ($json['error_message'] ?? $json['message'] ?? 'Plamod scraper error');
            if ($res->status() === 404) {
                $msg = 'Plamod scraper endpoint not found. Restart the pricing-tool-plamod-scraper container after scraper code changes.';
            }

            return [
                'ok' => false,
                'error_message' => $msg,
            ];
        }

        $payload = $json + ['ok' => (bool) ($json['ok'] ?? true)];
        if (($payload['ok'] ?? false) === false) {
            $payload['error_message'] = (string) ($payload['error_message'] ?? 'Plamod preorders CSV export failed');
        }

        return $payload;
    }

    /**
     * @return array{ok: bool, error_message?: string, csv_storage_path?: string, bytes?: int, row_count?: int, has_vigna_sku?: bool, has_vigna_name?: bool, duration_ms?: int}
     */
    public function exportManufacturerPreordersCsv(int $manufacturerId = 1): array
    {
        $url = rtrim($this->baseUrl, '/').'/export-manufacturer-preorders-csv';

        $res = Http::timeout(230)
            ->connectTimeout(10)
            ->retry(3, 250, function ($exception): bool {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
            ->post($url, ['manufacturer_id' => $manufacturerId]);

        /** @var array<string, mixed> $json */
        $json = $res->json() ?? [];

        if (! $res->successful()) {
            $msg = (string) ($json['error_message'] ?? $json['message'] ?? 'Plamod scraper error');
            if ($res->status() === 404) {
                $msg = 'Plamod scraper endpoint not found. Restart the pricing-tool-plamod-scraper container after scraper code changes.';
            }

            return [
                'ok' => false,
                'error_message' => $msg,
            ];
        }

        $payload = $json + ['ok' => (bool) ($json['ok'] ?? true)];
        if (($payload['ok'] ?? false) === false) {
            $payload['error_message'] = (string) ($payload['error_message'] ?? 'Plamod manufacturer preorders CSV export failed');
        }

        return $payload;
    }

    /**
     * @param  array<int, string>  $queries
     * @return array{ok: bool, error_message?: string, results?: array<string, array{sku: string, product_name: string, plamod_pdp_url: string, match_score?: int}|null>, duration_ms?: int}
     */
    public function searchRetailerPreorders(array $queries): array
    {
        $url = rtrim($this->baseUrl, '/').'/search-retailer-preorders';

        $res = Http::timeout(230)
            ->connectTimeout(10)
            ->retry(2, 250, function ($exception): bool {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
            ->post($url, ['queries' => array_values($queries)]);

        /** @var array<string, mixed> $json */
        $json = $res->json() ?? [];

        if (! $res->successful()) {
            return [
                'ok' => false,
                'error_message' => (string) ($json['error_message'] ?? 'Plamod scraper search failed'),
            ];
        }

        return $json + ['ok' => (bool) ($json['ok'] ?? true)];
    }
}
