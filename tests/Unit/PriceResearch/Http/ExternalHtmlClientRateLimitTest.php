<?php

declare(strict_types=1);

use App\Services\PriceResearch\Http\ExternalHtmlClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(Tests\TestCase::class);

it('hits the per-site rate limiter key for outbound requests', function (): void {
    Http::fake([
        'https://example.com/*' => Http::response('<html></html>', 200),
    ]);

    RateLimiter::shouldReceive('tooManyAttempts')
        ->once()
        ->with('price_research:site:panda_hobby', 10)
        ->andReturnFalse();

    RateLimiter::shouldReceive('hit')
        ->once()
        ->with('price_research:site:panda_hobby', 60)
        ->andReturn(1);

    $client = new ExternalHtmlClient;
    $res = $client->get('https://example.com/test', [], 'panda_hobby');

    expect($res->status())->toBe(200);
});
