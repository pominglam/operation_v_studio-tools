<?php

declare(strict_types=1);

use App\Services\Shopify\Admin\Write\ShopifyProductSetPayloadValidator;

it('requires productOptions when variants include optionValues', function (): void {
    expect(fn () => ShopifyProductSetPayloadValidator::assertValid([
        'id' => 'gid://shopify/Product/1',
        'variants' => [
            [
                'id' => 'gid://shopify/ProductVariant/1',
                'optionValues' => [
                    ['optionName' => 'Title', 'name' => 'Default Title'],
                ],
            ],
        ],
    ]))->toThrow(\InvalidArgumentException::class, 'productOptions');
});

it('accepts productOptions with variant optionValues', function (): void {
    ShopifyProductSetPayloadValidator::assertValid([
        'id' => 'gid://shopify/Product/1',
        'productOptions' => [
            [
                'name' => 'Title',
                'values' => [
                    ['name' => 'Default Title'],
                ],
            ],
        ],
        'variants' => [
            [
                'id' => 'gid://shopify/ProductVariant/1',
                'optionValues' => [
                    ['optionName' => 'Title', 'name' => 'Default Title'],
                ],
            ],
        ],
    ]);

    expect(true)->toBeTrue();
});

it('allows payloads without variants', function (): void {
    ShopifyProductSetPayloadValidator::assertValid([
        'id' => 'gid://shopify/Product/1',
        'status' => 'ACTIVE',
    ]);

    expect(true)->toBeTrue();
});
