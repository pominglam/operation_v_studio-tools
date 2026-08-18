<?php

declare(strict_types=1);

use App\Support\Shopify\Admin\Orders\ShopifyOrderGraphQlSubtotal;

it('parses currentSubtotalPriceSet shop money amount from a GraphQL order node', function (): void {
    $amount = ShopifyOrderGraphQlSubtotal::subtotalShopAmount([
        'currentSubtotalPriceSet' => [
            'shopMoney' => ['amount' => '123.456', 'currencyCode' => 'CAD'],
        ],
    ]);

    expect($amount)->toBe('123.46');
});

it('returns null when subtotal money is missing', function (): void {
    expect(ShopifyOrderGraphQlSubtotal::subtotalShopAmount([]))->toBeNull();
});
