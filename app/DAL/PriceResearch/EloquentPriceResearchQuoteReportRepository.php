<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\PriceResearchQuoteReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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

    public function findByIdOrFail(int $id): PriceResearchQuoteReport
    {
        /** @var PriceResearchQuoteReport|null $report */
        $report = PriceResearchQuoteReport::query()->with(['product'])->find($id);
        if ($report === null) {
            throw (new ModelNotFoundException)->setModel(PriceResearchQuoteReport::class, [$id]);
        }

        return $report;
    }

    public function markHandled(PriceResearchQuoteReport $report): PriceResearchQuoteReport
    {
        if ($report->handled_at === null) {
            $report->handled_at = now();
            $report->save();
        }

        return $report;
    }
}
