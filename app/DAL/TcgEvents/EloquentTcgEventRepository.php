<?php

declare(strict_types=1);

namespace App\DAL\TcgEvents;

use App\Models\TcgEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentTcgEventRepository implements TcgEventRepository
{
    public function upsertByExternalEventId(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return TcgEvent::query()->upsert(
            $rows,
            ['external_event_id'],
            [
                'source',
                'game_title_id',
                'game_title',
                'organizer_id',
                'store_name',
                'store_url',
                'store_sns_url',
                'phone_number',
                'country_code',
                'pref_code',
                'city',
                'postcode',
                'street_address',
                'lat',
                'lng',
                'event_series_id',
                'event_name',
                'excerpt',
                'start_datetime',
                'timezone',
                'apply_start_datetime',
                'accepting_on_the_day_start_time',
                'accepting_on_the_day_end_time',
                'status_id',
                'status',
                'entry_type',
                'lottery_method',
                'entry_fee',
                'entry_fee_currency_code',
                'capacity',
                'applicants',
                'game_format_ids',
                'format',
                'raw_payload',
                'fetched_at',
                'updated_at',
            ],
        );
    }

    public function paginate(
        int $perPage,
        ?string $search = null,
        ?string $startDate = null,
        ?string $status = null,
        ?string $format = null,
        bool $hideZeroApplicants = false,
    ): LengthAwarePaginator {
        $query = TcgEvent::query();

        if (is_string($startDate) && $startDate !== '') {
            $query->where('start_datetime', '>=', $startDate.' 00:00:00');
        }

        if (is_string($status) && $status !== '') {
            $query->where('status', '=', $status);
        }

        if (is_string($format) && $format !== '') {
            $query->where('format', '=', $format);
        }

        if ($hideZeroApplicants) {
            $query->where(function ($q): void {
                $q->whereNull('applicants')
                    ->orWhere('applicants', '>', 0);
            });
        }

        if (is_string($search) && trim($search) !== '') {
            $s = '%'.trim($search).'%';
            $query->where(function ($q) use ($s): void {
                $q->where('store_name', 'like', $s)
                    ->orWhere('street_address', 'like', $s)
                    ->orWhere('city', 'like', $s)
                    ->orWhere('event_name', 'like', $s);
            });
        }

        return $query
            ->orderBy('start_datetime')
            ->paginate($perPage);
    }

    public function latestFetchedAt(): ?Carbon
    {
        /** @var string|null $raw */
        $raw = DB::table('tcg_events')->max('fetched_at');

        return is_string($raw) ? Carbon::parse($raw) : null;
    }

    public function findByUuidOrFail(string $uuid): TcgEvent
    {
        /** @var TcgEvent $event */
        $event = TcgEvent::query()->where('uuid', '=', $uuid)->firstOrFail();

        return $event;
    }
}
