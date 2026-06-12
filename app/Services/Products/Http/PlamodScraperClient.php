<?php

declare(strict_types=1);

namespace App\Services\Products\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class PlamodScraperClient implements PlamodScraper
{
    private const int LONG_TIMEOUT_SECONDS = 400;

    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @return array{ok: bool, error_message?: string, sku?: string, pdp_url?: string, zip_storage_path?: string, metadata?: array<string, mixed>}
     */
    public function downloadZip(string $sku): array
    {
        return $this->decodeResponse(
            $this->post('/download-zip', ['sku' => $sku], self::LONG_TIMEOUT_SECONDS, 3),
            'Plamod scraper error',
        );
    }

    /**
     * @return array{ok: bool, error_message?: string, csv_storage_path?: string, bytes?: int, duration_ms?: int}
     */
    public function exportPreordersCsv(): array
    {
        $payload = $this->decodeResponse(
            $this->post('/export-preorders-csv', [], self::LONG_TIMEOUT_SECONDS, 3),
            'Plamod scraper error',
        );

        if (($payload['ok'] ?? false) === false && ! isset($payload['error_message'])) {
            $payload['error_message'] = 'Plamod preorders CSV export failed';
        }

        return $payload;
    }

    /**
     * @return array{ok: bool, error_message?: string, series?: array<int, array{name: string, preorder_count?: int|null, other_count?: int|null}>, category_lines?: array<int, array{name: string, preorder_count?: int|null, other_count?: int|null}>, duration_ms?: int}
     */
    public function listManufacturerPreorderFilters(int $manufacturerId = 1): array
    {
        return $this->decodeResponse(
            $this->post('/list-manufacturer-preorders-filters', ['manufacturer_id' => $manufacturerId], self::LONG_TIMEOUT_SECONDS, 3),
            'Plamod scraper list filters failed',
        );
    }

    /**
     * @return array{ok: bool, error_message?: string, csv_storage_path?: string, bytes?: int, row_count?: int, has_vigna_sku?: bool, has_vigna_name?: bool, duration_ms?: int, series?: string|null, category_line?: string|null}
     */
    public function exportManufacturerPreordersCsv(
        int $manufacturerId = 1,
        ?string $series = null,
        ?string $categoryLine = null,
    ): array {
        $body = ['manufacturer_id' => $manufacturerId];
        if ($series !== null && $series !== '') {
            $body['series'] = $series;
        }
        if ($categoryLine !== null && $categoryLine !== '') {
            $body['category_line'] = $categoryLine;
        }
        if ($series === null && $categoryLine === null) {
            $body['category'] = 'Plastic Model Kits';
        } else {
            $body['category'] = null;
        }

        $payload = $this->decodeResponse(
            $this->post('/export-manufacturer-preorders-csv', $body, self::LONG_TIMEOUT_SECONDS, 2),
            'Plamod scraper error',
            notFoundMessage: 'Plamod scraper endpoint not found. Restart the pricing-tool-plamod-scraper container after scraper code changes.',
        );

        if (($payload['ok'] ?? false) === false && ! isset($payload['error_message'])) {
            $payload['error_message'] = 'Plamod manufacturer preorders CSV export failed';
        }

        return $payload;
    }

    /**
     * @param  array<int, string>  $queries
     * @return array{ok: bool, error_message?: string, results?: array<string, array{sku: string, product_name: string, plamod_pdp_url: string, match_score?: int}|null>, duration_ms?: int}
     */
    public function searchRetailerPreorders(array $queries): array
    {
        return $this->decodeResponse(
            $this->post('/search-retailer-preorders', ['queries' => array_values($queries)], self::LONG_TIMEOUT_SECONDS, 2),
            'Plamod scraper search failed',
        );
    }

    /**
     * @return array{ok: bool, error_message?: string}
     */
    public function resetScraperSessions(): array
    {
        return $this->decodeResponse(
            $this->post('/reset-scraper-sessions', [], 15, 2),
            'Plamod scraper session reset failed',
        );
    }

    /**
     * @param  array<int, string>  $skus
     * @return array{ok: bool, error_message?: string, results?: array<string, array{image_url?: string, product_name?: string, price_preorder?: string, quantity_preorder?: string}|null>, enriched?: int, duration_ms?: int}
     */
    public function enrichPreorderPdpFields(array $skus): array
    {
        return $this->decodeResponse(
            $this->post('/enrich-preorder-pdp-fields', ['skus' => array_values($skus)], self::LONG_TIMEOUT_SECONDS, 2),
            'Plamod scraper PDP enrich failed',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body, int $timeoutSeconds, int $retries): ?Response
    {
        try {
            return Http::timeout($timeoutSeconds)
                ->connectTimeout(10)
                ->retry($retries, 500, function (\Throwable $exception): bool {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->post(rtrim($this->baseUrl, '/').$path, $body);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(?Response $response, string $fallbackMessage, ?string $notFoundMessage = null): array
    {
        if ($response === null) {
            return [
                'ok' => false,
                'error_message' => $fallbackMessage,
            ];
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        if (! $response->successful()) {
            $msg = (string) ($json['error_message'] ?? $json['message'] ?? $fallbackMessage);
            if ($response->status() === 404 && $notFoundMessage !== null) {
                $msg = $notFoundMessage;
            }

            return [
                'ok' => false,
                'error_message' => $msg,
            ];
        }

        $payload = $json + ['ok' => (bool) ($json['ok'] ?? true)];
        if (($payload['ok'] ?? false) === false && ! isset($payload['error_message'])) {
            $payload['error_message'] = $fallbackMessage;
        }

        return $payload;
    }
}
