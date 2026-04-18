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
}
