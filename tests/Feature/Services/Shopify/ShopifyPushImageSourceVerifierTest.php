<?php

declare(strict_types=1);

use App\Services\Shopify\Admin\Write\ShopifyPushImageSourceVerifier;
use Illuminate\Support\Facades\Http;

it('passes when every image originalSource responds with HTTP 200', function (): void {
    Http::fake([
        'https://tunnel.example/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    app(ShopifyPushImageSourceVerifier::class)->assertReachable([
        ['originalSource' => 'https://tunnel.example/shopify-images/a.png?signature=abc&expires=1'],
    ]);

    Http::assertSentCount(1);
});

it('throws when image originalSource is not reachable', function (): void {
    Http::fake([
        'https://tunnel.example/*' => Http::response('missing', 404),
    ]);

    expect(fn () => app(ShopifyPushImageSourceVerifier::class)->assertReachable([
        ['originalSource' => 'https://tunnel.example/shopify-images/missing.png'],
    ]))->toThrow(\InvalidArgumentException::class, 'Image URL not reachable');
});

it('throws when image file is missing originalSource', function (): void {
    expect(fn () => app(ShopifyPushImageSourceVerifier::class)->assertReachable([
        ['filename' => 'orphan.png'],
    ]))->toThrow(\InvalidArgumentException::class, 'missing originalSource');
});
