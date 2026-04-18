<?php

declare(strict_types=1);

namespace App\DAL\TcgEvents;

use App\Models\TcgEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TcgEventRepository
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function upsertByExternalEventId(array $rows): int;

    public function paginate(
        int $perPage,
        ?string $search = null,
        ?string $startDate = null,
        ?string $status = null,
        ?string $format = null,
        bool $hideZeroApplicants = false,
    ): LengthAwarePaginator;

    public function latestFetchedAt(): ?\Illuminate\Support\Carbon;

    public function findByUuidOrFail(string $uuid): TcgEvent;
}
