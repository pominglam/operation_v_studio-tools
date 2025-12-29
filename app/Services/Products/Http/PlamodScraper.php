<?php

declare(strict_types=1);

namespace App\Services\Products\Http;

interface PlamodScraper
{
    /**
     * @return array{ok: bool, error_message?: string, sku?: string, pdp_url?: string, zip_storage_path?: string, metadata?: array<string, mixed>}
     */
    public function downloadZip(string $sku): array;
}


