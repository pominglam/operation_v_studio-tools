<?php

declare(strict_types=1);

use App\Services\ShipmentTracking\HttpTrackingBrowserClient;
use Illuminate\Support\Facades\Http;

it('maps a successful tracking worker response', function (): void {
    Http::fake([
        'http://tracking.test/resolve' => Http::response([
            'status' => 'resolved',
            'provider' => 'kuaidi100',
            'tracking_url' => 'https://www.kuaidi100.com/?nu=520704842993',
        ]),
    ]);

    $result = (new HttpTrackingBrowserClient('http://tracking.test'))
        ->resolve('520704842993');

    expect($result->status)->toBe('resolved')
        ->and($result->provider)->toBe('kuaidi100')
        ->and($result->trackingUrl)->toBe('https://www.kuaidi100.com/?nu=520704842993');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://tracking.test/resolve'
        && $request['tracking_number'] === '520704842993');
});

it('maps an unavailable tracking worker to a retryable failure', function (): void {
    Http::fake([
        'http://tracking.test/resolve' => Http::response([], 503),
    ]);

    $result = (new HttpTrackingBrowserClient('http://tracking.test'))
        ->resolve('520701651454');

    expect($result->status)->toBe('failed')
        ->and($result->errorMessage)->toContain('HTTP 503');
});
