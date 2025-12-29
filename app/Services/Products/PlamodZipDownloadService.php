<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Services\Products\Http\PlamodScraper;

final class PlamodZipDownloadService
{
    public function __construct(private readonly PlamodScraper $scraper) {}

    /**
     * @return array{ok: bool, error_message?: string, sku?: string, pdp_url?: string, zip_storage_path?: string, metadata?: array<string, mixed>}
     */
    public function downloadZip(string $sku): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return ['ok' => false, 'error_message' => 'sku is required'];
        }

        $attempts = 0;
        $maxAttempts = 3;
        $sleepMs = 500;

        while (true) {
            $attempts++;
            $out = $this->scraper->downloadZip($sku);

            $ok = (bool) ($out['ok'] ?? false);
            if ($ok) {
                return $out;
            }

            $msg = (string) ($out['error_message'] ?? '');
            if ($attempts >= $maxAttempts || ! $this->isTransientError($msg)) {
                return $out;
            }

            // Backoff with jitter (avoid thundering herd on Plamod).
            $jitterMs = random_int(0, 250);
            usleep(($sleepMs + $jitterMs) * 1000);
            $sleepMs *= 2;
        }
    }

    private function isTransientError(string $message): bool
    {
        $m = mb_strtolower($message);

        return str_contains($m, 'timeout')
            || str_contains($m, 'download_save_timeout')
            || str_contains($m, 'page.waitforevent')
            || str_contains($m, 'net::');
    }
}


