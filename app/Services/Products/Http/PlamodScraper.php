<?php

declare(strict_types=1);

namespace App\Services\Products\Http;

interface PlamodScraper
{
    /**
     * @return array{ok: bool, error_message?: string, sku?: string, pdp_url?: string, zip_storage_path?: string, metadata?: array<string, mixed>}
     */
    public function downloadZip(string $sku): array;

    /**
     * @return array{ok: bool, error_message?: string, csv_storage_path?: string, bytes?: int, duration_ms?: int}
     */
    public function exportPreordersCsv(): array;

    /**
     * @return array{ok: bool, error_message?: string, csv_storage_path?: string, bytes?: int, row_count?: int, has_vigna_sku?: bool, has_vigna_name?: bool, duration_ms?: int}
     */
    public function exportManufacturerPreordersCsv(int $manufacturerId = 1): array;

    /**
     * @param  array<int, string>  $queries
     * @return array{ok: bool, error_message?: string, results?: array<string, array{sku: string, product_name: string, plamod_pdp_url: string, match_score?: int}|null>, duration_ms?: int}
     */
    public function searchRetailerPreorders(array $queries): array;
}
