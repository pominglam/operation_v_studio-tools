<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\PriceResearchQuoteReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PriceResearchQuoteReportRepository
{
    /**
     * @param array{
     *   product_id: int,
     *   product_uuid: string,
     *   sku: string,
     *   site_key: string,
     *   site_name: string,
     *   status?: string|null,
     *   availability?: string|null,
     *   currency?: string|null,
     *   price?: float|string|null,
     *   original_price?: float|string|null,
     *   product_url?: string|null,
     *   error_message?: string|null,
     *   fetched_at?: \DateTimeInterface|null,
     *   run_uuid?: string|null,
     *   note?: string|null
     * } $attributes
     */
    public function create(array $attributes): PriceResearchQuoteReport;

    public function paginate(int $perPage): LengthAwarePaginator;
}
