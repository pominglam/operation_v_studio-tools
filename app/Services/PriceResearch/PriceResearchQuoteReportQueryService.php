<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\DAL\PriceResearch\PriceResearchQuoteReportRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PriceResearchQuoteReportQueryService
{
    public function __construct(
        private readonly PriceResearchQuoteReportRepository $reports,
    ) {}

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->reports->paginate($perPage);
    }
}
