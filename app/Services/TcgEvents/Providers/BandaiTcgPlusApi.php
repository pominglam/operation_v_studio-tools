<?php

declare(strict_types=1);

namespace App\Services\TcgEvents\Providers;

interface BandaiTcgPlusApi
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{events: array<int, array<string, mixed>>, total: int}
     */
    public function listEvents(array $params): array;

    /**
     * @return array{event: array<string, mixed>, count_applicants: int|null}
     */
    public function getEventDetail(int $eventId): array;

    /**
     * Fetch multiple event details with best-effort concurrency.
     *
     * @param  array<int, int>  $eventIds
     * @return array<int, array{event: array<string, mixed>, count_applicants: int|null}>
     */
    public function getEventDetails(array $eventIds): array;

    /**
     * @return array<int, string> formatNameById
     */
    public function getGameFormatMap(): array;
}
