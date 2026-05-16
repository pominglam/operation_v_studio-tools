<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

beforeEach(function (): void {
    Config::set('shopify.store_domain', 'unit.myshopify.com');
    Config::set('shopify.api_version', '2025-10');
});

it('prints Shopify product connectivity rows using the Admin GraphQL client', function (): void {
    $fake = new FakeShopifyAdminGraphQlClient;
    $fake->queueResponse(FakeShopifyAdminGraphQlClient::wrapProducts(
        [[
            'id' => 'gid://shopify/Product/1',
            'handle' => 'test-handle',
            'title' => 'Test Title',
            'status' => 'ACTIVE',
            'vendor' => 'Acme Vendor',
            'productType' => 'Widget',
            'variants' => [
                'pageInfo' => ['hasNextPage' => false],
                'nodes' => [
                    ['id' => 'gid://shopify/ProductVariant/1'],
                    ['id' => 'gid://shopify/ProductVariant/2'],
                    ['id' => 'gid://shopify/ProductVariant/3'],
                ],
            ],
        ]],
        false,
        null,
    ));
    $this->app->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    $buf = new BufferedOutput;
    $exit = Artisan::call('shopify:test-products', ['--limit' => 1], $buf);
    $out = $buf->fetch();

    expect($exit)->toBe(0);
    expect($out)->toContain('gid://shopify/Product/1')
        ->and($out)->toContain('Test Title')
        ->and($out)->toContain('test-handle')
        ->and($out)->toContain('ACTIVE')
        ->and($out)->toContain('Acme Vendor')
        ->and($out)->toContain('Widget')
        ->and($out)->toContain('3');
});

it('fails cleanly when no OAuth token is persisted', function (): void {
    $buf = new BufferedOutput;
    $exit = Artisan::call('shopify:test-products', ['--limit' => 1], $buf);
    expect($exit)->not->toBe(0)
        ->and($buf->fetch())->toContain('No persisted Shopify Admin token');
});
