<?php

declare(strict_types=1);

use App\Models\TcgEvent;
use Illuminate\Support\Facades\Http;

it('refreshes tcg events from bandai and stores them', function (): void {
    Http::fake([
        'https://api.bandai-tcg-plus.com/api/masterdata*' => Http::response([
            'success' => [
                'code' => 200,
                'game_format' => [
                    '139' => [
                        'id' => 139,
                        'format_name' => 'Constructed',
                        'game_title_id' => 16,
                        'is_hidden_from_user' => false,
                    ],
                ],
                'master' => [],
            ],
        ], 200),
        'https://api.bandai-tcg-plus.com/api/user/event/list*' => Http::response([
            'success' => [
                'code' => 200,
                'event_list' => [
                    [
                        'id' => 123,
                        'status_id' => 11,
                        'start_datetime' => '2026-02-24T18:45:00',
                        'timezone' => 'America/Toronto',
                        'max_join_count' => 32,
                        'country_code' => 'CA',
                        'pref_code' => 'CA-QC',
                        'postcode' => 'H1Z2H7',
                        'city_code' => 'Montreal',
                        'street_address' => '3299 Boul Crémazie E',
                        'event_place_geo' => ['x' => 45.5636026, 'y' => -73.6065249],
                        'entry_type' => 1,
                        'event_series_id' => '5712',
                        'game_title_id' => '16',
                        'game_title' => 'GUNDAM CARD GAME',
                        'organizer_id' => 16153,
                        'organizer_name' => 'Jeux & Café The Side Deck, Inc.',
                        'organizer_url' => 'https://thesidedeck.ca/',
                        'event_series_title' => '[Jan-Mar 2026]Store Tournament Event GD',
                        'entryFee' => '15.000',
                        'entry_fee_currency_code' => 'CAD',
                        'game_format_ids' => [139],
                    ],
                ],
                'total' => 1,
            ],
        ], 200),
        'https://api.bandai-tcg-plus.com/api/user/event/123*' => Http::response([
            'success' => [
                'code' => 200,
                'event' => [
                    'id' => 123,
                    'event_series_title' => '[Jan-Mar 2026]Store Tournament Event GD',
                    'event_series_id' => '5712',
                    'organizer_id' => 16153,
                    'organizer_name' => 'Jeux & Café The Side Deck, Inc.',
                    'organizer_url' => 'https://thesidedeck.ca/',
                    'pref_code' => 'CA-QC',
                    'postcode' => 'H1Z2H7',
                    'city_address' => 'Montreal',
                    'street_address' => '3299 Boul Crémazie E',
                    'place_geo' => ['x' => 45.5636026, 'y' => -73.6065249],
                    'start_datetime' => '2026-02-24T18:45:00',
                    'timezone' => 'America/Toronto',
                    'excerpt' => 'Test excerpt',
                    'entry_type' => 1,
                    'entry_fee' => '15.000',
                    'entry_fee_currency_code' => 'CAD',
                    'max_join_count' => 32,
                    'status_id' => 11,
                    'country_code' => 'CA',
                    'game_title_id' => '16',
                    'game_title' => 'GUNDAM CARD GAME',
                    'game_format_ids' => [139],
                ],
                'count_applicants' => 7,
            ],
        ], 200),
    ]);

    $this->postJson('/api/v1/tcg/events/refresh', [
        'start_date' => '2026-02-23',
        'street_address' => 'montreal',
        'pref_code' => 'CA-QC',
    ])->assertOk()->assertJsonPath('data.fetched_events', 1);

    expect(TcgEvent::query()->count())->toBe(1);
    $event = TcgEvent::query()->firstOrFail();
    expect($event->external_event_id)->toBe(123);
    expect($event->format)->toBe('Constructed');
    expect($event->applicants)->toBe(7);
});

it('validates refresh requests', function (): void {
    $this->postJson('/api/v1/tcg/events/refresh', [])
        ->assertStatus(422);
});

it('lists tcg events with basic filters', function (): void {
    TcgEvent::query()->create([
        'external_event_id' => 1,
        'game_title_id' => 16,
        'store_name' => 'Store A',
        'event_name' => 'Event A',
        'start_datetime' => '2026-02-23 10:00:00',
        'raw_payload' => [],
        'fetched_at' => now(),
        'status' => 'accepting',
        'format' => 'Constructed',
        'applicants' => 0,
    ]);
    TcgEvent::query()->create([
        'external_event_id' => 2,
        'game_title_id' => 16,
        'store_name' => 'Store B',
        'event_name' => 'Event B',
        'start_datetime' => '2026-02-25 10:00:00',
        'raw_payload' => [],
        'fetched_at' => now(),
        'status' => 'finished',
        'format' => 'Sealed',
        'applicants' => 5,
    ]);

    $this->getJson('/api/v1/tcg/events?per_page=100&search=Store%20A&start_date=2026-02-23&status=accepting')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.store_name', 'Store A');
});

it('can hide events with 0 applicants', function (): void {
    TcgEvent::query()->create([
        'external_event_id' => 10,
        'game_title_id' => 16,
        'store_name' => 'Zero Applicants',
        'event_name' => 'Event Z',
        'start_datetime' => '2026-02-23 10:00:00',
        'raw_payload' => [],
        'fetched_at' => now(),
        'status' => 'accepting',
        'format' => 'Constructed',
        'applicants' => 0,
    ]);
    TcgEvent::query()->create([
        'external_event_id' => 11,
        'game_title_id' => 16,
        'store_name' => 'Some Applicants',
        'event_name' => 'Event S',
        'start_datetime' => '2026-02-23 11:00:00',
        'raw_payload' => [],
        'fetched_at' => now(),
        'status' => 'accepting',
        'format' => 'Constructed',
        'applicants' => 2,
    ]);

    $this->getJson('/api/v1/tcg/events?per_page=100&hide_zero_applicants=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissing(['store_name' => 'Zero Applicants'])
        ->assertJsonPath('data.0.store_name', 'Some Applicants');
});
