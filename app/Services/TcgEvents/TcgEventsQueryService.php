<?php

declare(strict_types=1);

namespace App\Services\TcgEvents;

use App\DAL\TcgEvents\TcgEventRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class TcgEventsQueryService
{
    public function __construct(private readonly TcgEventRepository $events) {}

    /**
     * @return array{paginator: LengthAwarePaginator, latestFetchedAt: Carbon|null}
     */
    public function paginate(
        int $perPage,
        ?string $search = null,
        ?string $startDate = null,
        ?string $status = null,
        ?string $format = null,
        bool $hideZeroApplicants = false,
    ): array {
        $paginator = $this->events->paginate(
            perPage: $perPage,
            search: $search,
            startDate: $startDate,
            status: $status,
            format: $format,
            hideZeroApplicants: $hideZeroApplicants,
        );

        return [
            'paginator' => $paginator,
            'latestFetchedAt' => $this->events->latestFetchedAt(),
        ];
    }
}
