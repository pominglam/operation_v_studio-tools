<?php

declare(strict_types=1);

use App\Services\Shopify\Admin\Support\ShopifyGraphQlNodeParser;
use Illuminate\Support\Carbon;

it('converts shopify iso timestamps to America/Toronto wall clock', function (): void {
    $eastern = ShopifyGraphQlNodeParser::timestampInShopTz('2026-06-08T01:29:55Z');

    expect($eastern)->not->toBeNull()
        ->and($eastern)->toBeInstanceOf(Carbon::class)
        ->and($eastern?->timezoneName)->toBe('America/Toronto')
        ->and($eastern?->format('Y-m-d H:i:s'))->toBe('2026-06-07 21:29:55');
});

it('returns null for empty shopify timestamps', function (): void {
    expect(ShopifyGraphQlNodeParser::timestampInShopTz(null))->toBeNull()
        ->and(ShopifyGraphQlNodeParser::timestampInShopTz(''))->toBeNull();
});
