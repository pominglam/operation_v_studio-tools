<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\PriceResearchRun;
use App\Models\PriceResearchRunLog;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PriceResearchRunLogRepository
{
    public function start(PriceResearchRun $run, Product $product, string $siteKey, string $siteName): PriceResearchRunLog;

    public function finish(
        PriceResearchRunLog $log,
        string $status,
        ?string $productUrl,
        ?string $errorMessage,
        int $durationMs,
    ): PriceResearchRunLog;

    public function paginateForRunUuid(string $runUuid, int $perPage): LengthAwarePaginator;
}
