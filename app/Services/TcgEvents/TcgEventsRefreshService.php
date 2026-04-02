<?php

declare(strict_types=1);

namespace App\Services\TcgEvents;

use App\DAL\TcgEvents\TcgEventRepository;
use App\DTOs\TcgEvents\TcgEventsRefreshResultDTO;
use App\Services\TcgEvents\Providers\BandaiTcgPlusApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class TcgEventsRefreshService
{
    public function __construct(
        private readonly BandaiTcgPlusApi $bandai,
        private readonly TcgEventRepository $events,
    ) {}

    public function refreshBandaiEvents(
        string $startDate,
        string $streetAddress = 'montreal',
        string $countryCode = 'CA',
        string $prefCode = 'CA-QC',
        int $gameTitleId = 16,
        int $limit = 100,
    ): TcgEventsRefreshResultDTO {
        $fetchedAt = now();
        $formatNameById = $this->bandai->getGameFormatMap();

        $all = $this->fetchAllListEvents(
            startDate: $startDate,
            streetAddress: $streetAddress,
            countryCode: $countryCode,
            prefCode: $prefCode,
            gameTitleId: $gameTitleId,
            limit: $limit,
        );

        $externalIds = [];
        foreach ($all as $listEvent) {
            $externalId = $listEvent['id'] ?? null;
            if (is_int($externalId)) {
                $externalIds[] = $externalId;
            }
        }
        $detailsById = $this->bandai->getEventDetails($externalIds);

        $rows = [];
        foreach ($all as $listEvent) {
            $externalId = $listEvent['id'] ?? null;
            if (! is_int($externalId)) {
                continue;
            }

            $detail = $detailsById[$externalId] ?? null;
            if (! is_array($detail) || ! isset($detail['event']) || ! is_array($detail['event'])) {
                $detail = [
                    'event' => $listEvent,
                    'count_applicants' => null,
                ];
            }
            $row = $this->toRow(
                listEvent: $listEvent,
                detailEvent: $detail['event'],
                applicants: $detail['count_applicants'],
                formatNameById: $formatNameById,
                fetchedAt: $fetchedAt,
            );
            $rows[] = $row;
        }

        $upserted = $this->events->upsertByExternalEventId($rows);

        return new TcgEventsRefreshResultDTO(
            fetchedEvents: count($rows),
            upsertedEvents: $upserted,
            fetchedAt: Carbon::parse($fetchedAt->toISOString()),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllListEvents(
        string $startDate,
        string $streetAddress,
        string $countryCode,
        string $prefCode,
        int $gameTitleId,
        int $limit,
    ): array {
        $events = [];
        $offset = 0;

        while (true) {
            $page = $this->bandai->listEvents([
                'application_open_flg' => 0,
                'favorite' => 0,
                'country_code[]' => $countryCode,
                'pref_code[]' => $prefCode,
                'game_title_id' => $gameTitleId,
                'limit' => $limit,
                'offset' => $offset,
                'order' => 1,
                'start_date' => $startDate,
                'street_address' => $streetAddress,
            ]);

            $events = [...$events, ...$page['events']];
            $offset += $limit;

            if (count($events) >= $page['total']) {
                break;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $listEvent
     * @param  array<string, mixed>  $detailEvent
     * @param  array<int, string>  $formatNameById
     * @return array<string, mixed>
     */
    private function toRow(array $listEvent, array $detailEvent, ?int $applicants, array $formatNameById, Carbon $fetchedAt): array
    {
        $placeGeo = $detailEvent['place_geo'] ?? $listEvent['event_place_geo'] ?? null;
        $lat = is_array($placeGeo) ? ($placeGeo['x'] ?? null) : null;
        $lng = is_array($placeGeo) ? ($placeGeo['y'] ?? null) : null;

        $gameFormatIds = $detailEvent['game_format_ids'] ?? $listEvent['game_format_ids'] ?? null;
        $gameFormatIds = is_array($gameFormatIds) ? array_values(array_filter($gameFormatIds, 'is_int')) : null;
        $format = $this->formatName($gameFormatIds, $formatNameById);

        $statusId = $detailEvent['status_id'] ?? $listEvent['status_id'] ?? null;
        $statusId = is_int($statusId) ? $statusId : null;

        $entryType = $detailEvent['entry_type'] ?? $listEvent['entry_type'] ?? null;
        $entryType = is_int($entryType) ? $entryType : null;

        $raw = [
            'list' => $listEvent,
            'detail' => $detailEvent,
            'count_applicants' => $applicants,
        ];
        $rawJson = json_encode($raw, JSON_THROW_ON_ERROR);
        $gameFormatIdsJson = $gameFormatIds !== null ? json_encode($gameFormatIds, JSON_THROW_ON_ERROR) : null;

        return [
            'uuid' => (string) Str::uuid(),
            'source' => 'bandai_tcg_plus',
            'external_event_id' => $detailEvent['id'] ?? $listEvent['id'],
            'game_title_id' => (int) ($detailEvent['game_title_id'] ?? $listEvent['game_title_id'] ?? 0),
            'game_title' => $detailEvent['game_title'] ?? $listEvent['game_title'] ?? null,
            'organizer_id' => is_numeric($detailEvent['organizer_id'] ?? null) ? (int) $detailEvent['organizer_id'] : ($listEvent['organizer_id'] ?? null),
            'store_name' => (string) ($detailEvent['organizer_name'] ?? $listEvent['organizer_name'] ?? ''),
            'store_url' => $detailEvent['organizer_url'] ?? $listEvent['organizer_url'] ?? null,
            'store_sns_url' => $detailEvent['organizer_sns_url'] ?? $listEvent['organizer_sns_url'] ?? null,
            'phone_number' => $detailEvent['phone_number'] ?? $listEvent['phone_number'] ?? null,
            'country_code' => $detailEvent['country_code'] ?? $listEvent['country_code'] ?? null,
            'pref_code' => $detailEvent['pref_code'] ?? $listEvent['pref_code'] ?? null,
            'city' => $detailEvent['city_address'] ?? $listEvent['city_code'] ?? null,
            'postcode' => $detailEvent['postcode'] ?? $listEvent['postcode'] ?? null,
            'street_address' => $detailEvent['street_address'] ?? $listEvent['street_address'] ?? null,
            'lat' => is_numeric($lat) ? (string) $lat : null,
            'lng' => is_numeric($lng) ? (string) $lng : null,
            'event_series_id' => is_numeric($detailEvent['event_series_id'] ?? null) ? (int) $detailEvent['event_series_id'] : null,
            'event_name' => (string) ($detailEvent['event_series_title'] ?? $listEvent['event_series_title'] ?? ''),
            'excerpt' => $detailEvent['excerpt'] ?? $listEvent['excerpt'] ?? null,
            'start_datetime' => $this->normalizeDatetime($detailEvent['start_datetime'] ?? $listEvent['start_datetime'] ?? null),
            'timezone' => $detailEvent['timezone'] ?? $listEvent['timezone'] ?? null,
            'apply_start_datetime' => $this->normalizeDatetime($detailEvent['apply_start_datetime'] ?? $listEvent['apply_start_datetime'] ?? null),
            'accepting_on_the_day_start_time' => $this->normalizeDatetime($listEvent['accepting_on_the_day_start_time'] ?? null),
            'accepting_on_the_day_end_time' => $this->normalizeDatetime($listEvent['accepting_on_the_day_end_time'] ?? null),
            'status_id' => $statusId,
            'status' => $this->statusName($statusId),
            'entry_type' => $entryType,
            'lottery_method' => $this->entryMethodName($entryType),
            'entry_fee' => $detailEvent['entry_fee'] ?? $listEvent['entryFee'] ?? null,
            'entry_fee_currency_code' => $detailEvent['entry_fee_currency_code'] ?? $listEvent['entry_fee_currency_code'] ?? null,
            'capacity' => $detailEvent['max_join_count'] ?? $listEvent['max_join_count'] ?? null,
            'applicants' => $applicants,
            'game_format_ids' => $gameFormatIdsJson,
            'format' => $format,
            'raw_payload' => $rawJson,
            'fetched_at' => $fetchedAt,
            'created_at' => $fetchedAt,
            'updated_at' => $fetchedAt,
        ];
    }

    /**
     * @param  array<int, int>|null  $gameFormatIds
     * @param  array<int, string>  $formatNameById
     */
    private function formatName(?array $gameFormatIds, array $formatNameById): ?string
    {
        if (! is_array($gameFormatIds) || $gameFormatIds === []) {
            return null;
        }

        $first = $gameFormatIds[0] ?? null;
        if (! is_int($first)) {
            return null;
        }

        return $formatNameById[$first] ?? null;
    }

    private function statusName(?int $statusId): ?string
    {
        if (! is_int($statusId)) {
            return null;
        }

        return match (true) {
            $statusId >= 61 => 'finished',
            $statusId >= 51 => 'running',
            $statusId >= 31 => 'winner fixed',
            $statusId >= 21 => 'accepted',
            $statusId >= 11 => 'accepting',
            default => null,
        };
    }

    private function entryMethodName(?int $entryType): ?string
    {
        return match ($entryType) {
            1 => 'First come',
            2 => 'Lottery',
            3 => 'Advance',
            default => null,
        };
    }

    private function normalizeDatetime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateTimeString();
    }
}

