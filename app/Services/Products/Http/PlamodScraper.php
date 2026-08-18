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
     * @return array{ok: bool, error_message?: string, series?: array<int, array{name: string, preorder_count?: int|null, other_count?: int|null}>, category_lines?: array<int, array{name: string, preorder_count?: int|null, other_count?: int|null}>, duration_ms?: int}
     */
    public function listManufacturerPreorderFilters(int $manufacturerId = 1): array;

    /**
     * @return array{ok: bool, error_message?: string, csv_storage_path?: string, bytes?: int, row_count?: int, has_vigna_sku?: bool, has_vigna_name?: bool, duration_ms?: int, series?: string|null, category_line?: string|null}
     */
    public function exportManufacturerPreordersCsv(
        int $manufacturerId = 1,
        ?string $series = null,
        ?string $categoryLine = null,
        string $tab = 'Preorder',
    ): array;

    /**
     * @return array{
     *   ok: bool,
     *   error_message?: string,
     *   csv_storage_path?: string,
     *   bytes?: int,
     *   row_count?: int,
     *   expected_row_count?: int,
     *   filter_mode?: string|null,
     *   filter_chunks?: array<int, array<string, mixed>>,
     *   duration_ms?: int
     * }
     */
    public function exportManufacturerInstockMerged(int $manufacturerId = 1): array;

    /**
     * @return array{ok: bool, active: bool, phase?: string, filters_total?: int, filters_processed?: int, current_filter?: string, error_message?: string}
     */
    public function instockExportProgress(): array;

    /**
     * @param  array<int, string>  $queries
     * @return array{ok: bool, error_message?: string, results?: array<string, array{sku: string, product_name: string, plamod_pdp_url: string, match_score?: int}|null>, duration_ms?: int}
     */
    public function searchRetailerPreorders(array $queries): array;

    /**
     * @return array{ok: bool, error_message?: string}
     */
    public function resetScraperSessions(): array;

    /**
     * @param  array<int, string>  $skus
     * @return array{ok: bool, error_message?: string, results?: array<string, array{image_url?: string, product_name?: string, price_preorder?: string, quantity_preorder?: string}|null>, enriched?: int, duration_ms?: int}
     */
    public function enrichPreorderPdpFields(array $skus): array;

    /**
     * @param  array<int, array{sku: string, qty: int, product_name?: string, source?: string}>  $items
     * @return array{ok: bool, error_message?: string, duration_ms?: int, report?: array<string, mixed>, lines?: array<int, array<string, mixed>>}
     */
    public function restockAddToCart(array $items): array;

    /**
     * @return array{ok: bool, active?: bool, phase?: string, items_total?: int, items_processed?: int, current_sku?: string, report?: array<string, mixed>, error_message?: string}
     */
    public function restockCartProgress(): array;

    /**
     * @param  array{cart_before?: array<string, int>, lines?: array<int, array<string, mixed>>}  $payload
     * @return array{ok: bool, error_message?: string, duration_ms?: int, report?: array<string, mixed>}
     */
    public function restockVerifyCart(array $payload): array;
}
