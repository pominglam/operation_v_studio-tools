<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\PriceResearchRun;

interface PriceResearchRunRepository
{
    /**
     * @param  array<int, string>|null  $productUuids
     * @param  array<int, string>|null  $siteKeys
     */
    public function create(
        bool $force,
        int $ttlDays,
        int $totalSites,
        int $totalProducts,
        ?array $productUuids = null,
        ?array $siteKeys = null,
    ): PriceResearchRun;

    public function findByUuidOrFail(string $uuid): PriceResearchRun;

    public function latest(): ?PriceResearchRun;

    public function save(PriceResearchRun $run): PriceResearchRun;
}
