<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use Illuminate\Support\Facades\Http;

final class PlamodScraperHealthService
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    /**
     * @return array{ok: bool, error_message?: string}
     */
    public function assertPreordersExportReady(): array
    {
        $url = rtrim($this->baseUrl, '/').'/health';

        try {
            $res = Http::timeout(5)->connectTimeout(3)->get($url);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error_message' => 'Plamod scraper is unreachable: '.$e->getMessage(),
            ];
        }

        /** @var array<string, mixed> $json */
        $json = $res->json() ?? [];

        if (! $res->successful() || ($json['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'error_message' => 'Plamod scraper health check failed.',
            ];
        }

        $routes = $json['routes'] ?? null;
        if (! is_array($routes)) {
            return [
                'ok' => false,
                'error_message' => 'Plamod scraper is running outdated code. Restart the pricing-tool-plamod-scraper container, then retry.',
            ];
        }

        $required = [
            'POST /export-preorders-csv',
            'POST /export-manufacturer-preorders-csv',
            'POST /list-manufacturer-preorders-filters',
            'POST /search-retailer-preorders',
        ];
        $missing = array_values(array_filter($required, static fn (string $route): bool => ! in_array($route, $routes, true)));
        if ($missing !== []) {
            return [
                'ok' => false,
                'error_message' => 'Plamod scraper is running outdated code. Restart the pricing-tool-plamod-scraper container, then retry.',
            ];
        }

        return ['ok' => true];
    }
}
