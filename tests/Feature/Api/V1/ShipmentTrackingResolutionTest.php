<?php

declare(strict_types=1);

use App\Jobs\ShipmentTracking\ResolveShipmentTrackingJob;
use App\Models\ShipmentTrackingResolution;
use Illuminate\Support\Facades\Queue;

it('queues unresolved tracking numbers without blocking the request', function (): void {
    Queue::fake();

    $response = $this->postJson('/api/v1/shipment-tracking/resolutions', [
        'tracking_numbers' => [' 520704842993 ', '520701651454', '520704842993'],
    ]);

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.tracking_number', '520704842993')
        ->assertJsonPath('data.0.status', 'queued')
        ->assertJsonPath('data.0.tracking_url', null)
        ->assertJsonPath('data.1.tracking_number', '520701651454')
        ->assertJsonPath('data.1.status', 'queued');

    Queue::assertPushed(ResolveShipmentTrackingJob::class, 2);
    $this->assertDatabaseCount('shipment_tracking_resolutions', 2);
});

it('reuses a successful cached provider without dispatching another probe', function (): void {
    Queue::fake();

    ShipmentTrackingResolution::query()->create([
        'tracking_key' => hash('sha256', '520704842993'),
        'tracking_number' => '520704842993',
        'status' => 'resolved',
        'provider' => 'kuaidi100',
        'tracking_url' => 'https://www.kuaidi100.com/?nu=520704842993',
        'attempt_count' => 1,
        'last_attempted_at' => now(),
        'resolved_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/shipment-tracking/resolutions', [
        'tracking_numbers' => ['520704842993'],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.0.status', 'resolved')
        ->assertJsonPath('data.0.provider', 'kuaidi100')
        ->assertJsonPath(
            'data.0.tracking_url',
            'https://www.kuaidi100.com/?nu=520704842993',
        );

    Queue::assertNothingPushed();
});

it('rejects invalid or excessive tracking-number requests', function (): void {
    $this->postJson('/api/v1/shipment-tracking/resolutions', [
        'tracking_numbers' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('tracking_numbers');

    $this->postJson('/api/v1/shipment-tracking/resolutions', [
        'tracking_numbers' => [str_repeat('A', 256)],
    ])->assertUnprocessable()->assertJsonValidationErrors('tracking_numbers.0');
});
