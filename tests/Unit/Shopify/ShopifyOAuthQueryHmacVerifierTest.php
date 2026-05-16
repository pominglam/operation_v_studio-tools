<?php

declare(strict_types=1);

use App\Services\Shopify\Admin\Auth\ShopifyOAuthQueryHmacVerifier;

it('builds alphabetical signing messages Shopify expects', function (): void {
    $incoming = [
        'code' => 'aa',
        'shop' => 'orchid.myshopify.com',
        'timestamp' => '123',
        'state' => 'nonce',
        'hmac' => 'deadbeef',
        'embedded' => '1',
    ];

    $stripped = ShopifyOAuthQueryHmacVerifier::normalizeParamsForSigning($incoming);
    expect($stripped)->not->toHaveKey('hmac')
        ->and($stripped['state'])->toBe('nonce');

    $msg = ShopifyOAuthQueryHmacVerifier::buildMessageSorted($stripped);

    expect($msg)->toBe('code=aa&embedded=1&shop=orchid.myshopify.com&state=nonce&timestamp=123');

    $good = hash_hmac('sha256', $msg, 'sek', false);
    expect(ShopifyOAuthQueryHmacVerifier::verify($msg, $good, 'sek'))->toBeTrue()
        ->and(ShopifyOAuthQueryHmacVerifier::verify($msg, 'beef', 'sek'))->toBeFalse();
});
