<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\PriceResearchQuoteReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentPriceResearchQuoteReportRepository implements PriceResearchQuoteReportRepository
{
    public function create(array $attributes): PriceResearchQuoteReport
    {
        /** @var PriceResearchQuoteReport $report */
        $report = PriceResearchQuoteReport::query()->create($attributes);

        return $report;
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return PriceResearchQuoteReport::query()
            ->with(['product'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
