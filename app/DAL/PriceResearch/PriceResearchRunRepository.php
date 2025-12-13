<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\PriceResearchRun;

interface PriceResearchRunRepository
{
    public function create(bool $force, int $ttlDays, int $totalSites, int $totalProducts): PriceResearchRun;

    public function findByUuidOrFail(string $uuid): PriceResearchRun;

    public function latest(): ?PriceResearchRun;

    public function save(PriceResearchRun $run): PriceResearchRun;
}


