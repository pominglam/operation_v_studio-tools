<?php

declare(strict_types=1);

use App\Support\Products\ProductHoldQty;

it('computes sellable quantity from available minus hold', function (): void {
    expect(ProductHoldQty::sellableFromAvailable(12, 5))->toBe(7)
        ->and(ProductHoldQty::sellableFromAvailable(3, 8))->toBe(0)
        ->and(ProductHoldQty::sellableFromAvailable(null, null))->toBe(0);
});

it('computes erp available from shopify pull plus hold', function (): void {
    expect(ProductHoldQty::erpAvailableFromShopifyQty(7, 3))->toBe(10)
        ->and(ProductHoldQty::erpAvailableFromShopifyQty(0, 2))->toBe(2);
});

it('throws when hold exceeds available', function (): void {
    ProductHoldQty::assertHoldWithinAvailable(5, 4);
})->throws(\App\Support\Products\InvalidProductHoldQtyException::class);
