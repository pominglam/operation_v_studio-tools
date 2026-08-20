<?php

declare(strict_types=1);

use App\Contracts\ShipmentTracking\TrackingBrowser;
use App\DTOs\ShipmentTracking\TrackingProbeResultDTO;
use App\Jobs\ShipmentTracking\ResolveShipmentTrackingJob;
use App\Models\ShipmentTrackingResolution;
use App\Services\ShipmentTracking\ShipmentTrackingResolveService;

it('persists the first provider that returned real shipment events', function (): void {
    $resolution = ShipmentTrackingResolution::query()->create([
        'tracking_key' => hash('sha256', '520704842993'),
        'tracking_number' => '520704842993',
        'status' => 'queued',
        'attempt_count' => 0,
    ]);

    app()->bind(TrackingBrowser::class, fn (): TrackingBrowser => new class implements TrackingBrowser
    {
        public function resolve(string $trackingNumber): TrackingProbeResultDTO
        {
            expect($trackingNumber)->toBe('520704842993');

            return TrackingProbeResultDTO::resolved(
                provider: 'kuaidi100',
                trackingUrl: 'https://www.kuaidi100.com/?nu=520704842993',
            );
        }
    });

    (new ResolveShipmentTrackingJob((int) $resolution->id))
        ->handle(app(ShipmentTrackingResolveService::class));

    $resolution->refresh();

    expect($resolution->status)->toBe('resolved')
        ->and($resolution->provider)->toBe('kuaidi100')
        ->and($resolution->tracking_url)->toBe('https://www.kuaidi100.com/?nu=520704842993')
        ->and($resolution->attempt_count)->toBe(1)
        ->and($resolution->resolved_at)->not->toBeNull()
        ->and($resolution->retry_after)->toBeNull();
});

it('stores a retryable unavailable result without creating a link', function (): void {
    $resolution = ShipmentTrackingResolution::query()->create([
        'tracking_key' => hash('sha256', '520701651454'),
        'tracking_number' => '520701651454',
        'status' => 'queued',
        'attempt_count' => 0,
    ]);

    app()->bind(TrackingBrowser::class, fn (): TrackingBrowser => new class implements TrackingBrowser
    {
        public function resolve(string $trackingNumber): TrackingProbeResultDTO
        {
            return TrackingProbeResultDTO::notFound();
        }
    });

    (new ResolveShipmentTrackingJob((int) $resolution->id))
        ->handle(app(ShipmentTrackingResolveService::class));

    $resolution->refresh();

    expect($resolution->status)->toBe('not_found')
        ->and($resolution->provider)->toBeNull()
        ->and($resolution->tracking_url)->toBeNull()
        ->and($resolution->retry_after)->not->toBeNull();
});
