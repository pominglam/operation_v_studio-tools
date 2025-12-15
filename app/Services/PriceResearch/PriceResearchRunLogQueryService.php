<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\DAL\PriceResearch\PriceResearchRunLogRepository;
use App\DAL\PriceResearch\PriceResearchRunRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PriceResearchRunLogQueryService
{
    public function __construct(
        private readonly PriceResearchRunLogRepository $logs,
        private readonly PriceResearchRunRepository $runs,
    ) {}

    public function paginateForRunUuid(string $runUuid, int $perPage): LengthAwarePaginator
    {
        // Ensure the run exists (otherwise treat it as a 404).
        $this->runs->findByUuidOrFail($runUuid);

        return $this->logs->paginateForRunUuid($runUuid, $perPage);
    }
}
