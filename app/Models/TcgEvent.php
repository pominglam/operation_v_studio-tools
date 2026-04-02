<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $source
 * @property int $external_event_id
 * @property int $game_title_id
 * @property string|null $game_title
 * @property int|null $organizer_id
 * @property string $store_name
 * @property string|null $store_url
 * @property string|null $store_sns_url
 * @property string|null $phone_number
 * @property string|null $country_code
 * @property string|null $pref_code
 * @property string|null $city
 * @property string|null $postcode
 * @property string|null $street_address
 * @property string|null $lat
 * @property string|null $lng
 * @property int|null $event_series_id
 * @property string $event_name
 * @property string|null $excerpt
 * @property \Illuminate\Support\Carbon $start_datetime
 * @property string|null $timezone
 * @property \Illuminate\Support\Carbon|null $apply_start_datetime
 * @property \Illuminate\Support\Carbon|null $accepting_on_the_day_start_time
 * @property \Illuminate\Support\Carbon|null $accepting_on_the_day_end_time
 * @property int|null $status_id
 * @property string|null $status
 * @property int|null $entry_type
 * @property string|null $lottery_method
 * @property string|null $entry_fee
 * @property string|null $entry_fee_currency_code
 * @property int|null $capacity
 * @property int|null $applicants
 * @property array<int, int>|null $game_format_ids
 * @property string|null $format
 * @property array<string, mixed> $raw_payload
 * @property \Illuminate\Support\Carbon $fetched_at
 */
final class TcgEvent extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'source',
        'external_event_id',
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
    ];

    /** @var array<string, string> */
    protected $casts = [
        'external_event_id' => 'integer',
        'game_title_id' => 'integer',
        'organizer_id' => 'integer',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'event_series_id' => 'integer',
        'start_datetime' => 'datetime',
        'apply_start_datetime' => 'datetime',
        'accepting_on_the_day_start_time' => 'datetime',
        'accepting_on_the_day_end_time' => 'datetime',
        'status_id' => 'integer',
        'entry_type' => 'integer',
        'entry_fee' => 'decimal:3',
        'capacity' => 'integer',
        'applicants' => 'integer',
        'game_format_ids' => 'array',
        'raw_payload' => 'array',
        'fetched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            if (($event->uuid ?? '') === '') {
                $event->uuid = (string) Str::uuid();
            }
        });
    }
}

