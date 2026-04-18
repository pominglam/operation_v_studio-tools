<?php

declare(strict_types=1);

use App\Services\Shopify\CloudflareQuickTunnelVerifier;
use App\Services\Shopify\ShopifyImageUrlSigner;
use Illuminate\Support\Facades\Http;

it('treats any HTTP response as reachable (including expected 404 from missing signature)', function (): void {
    Http::preventStrayRequests();
    $expires = now()->addMinutes(5)->getTimestamp();
    $sig = app(ShopifyImageUrlSigner::class)->sign(0, $expires)['signature'];
    Http::fake([
        "https://abc.trycloudflare.com/shopify-images/0/{$expires}/{$sig}" => Http::response('not found', 404),
    ]);

    $v = new CloudflareQuickTunnelVerifier;
    $res = $v->verify('https://abc.trycloudflare.com');

    expect($res['reachable'])->toBeTrue();
    expect($res['http_status'])->toBe(404);
    expect($res['error'])->toBeNull();
});

it('treats 5xx from cloudflare as not reachable (not routing to origin)', function (): void {
    Http::preventStrayRequests();
    $expires = now()->addMinutes(5)->getTimestamp();
    $sig = app(ShopifyImageUrlSigner::class)->sign(0, $expires)['signature'];
    Http::fake([
        "https://abc.trycloudflare.com/shopify-images/0/{$expires}/{$sig}" => Http::response('origin error', 530),
    ]);

    $v = new CloudflareQuickTunnelVerifier;
    $res = $v->verify('https://abc.trycloudflare.com');

    expect($res['reachable'])->toBeFalse();
    expect($res['http_status'])->toBe(530);
});

it('reports unreachable when the request fails', function (): void {
    Http::preventStrayRequests();
    $expires = now()->addMinutes(5)->getTimestamp();
    $sig = app(ShopifyImageUrlSigner::class)->sign(0, $expires)['signature'];
    Http::fake([
        "https://bad.trycloudflare.com/shopify-images/0/{$expires}/{$sig}" => function () {
            throw new RuntimeException('connect failed');
        },
    ]);

    $v = new CloudflareQuickTunnelVerifier;
    $res = $v->verify('https://bad.trycloudflare.com');

    expect($res['reachable'])->toBeNull();
    expect($res['http_status'])->toBeNull();
    expect($res['error'])->toContain('connect failed');
});
