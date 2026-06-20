<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Exceptions\Shopify\ShopifyGraphQlException;
use App\Services\Shopify\Admin\Write\ShopifyProductMediaProcessingWaiter;
use Tests\Fakes\FakeShopifyAdminGraphQlClient;

it('waits until Shopify media status becomes READY', function (): void {
    $fake = new class implements ShopifyAdminGraphQlClientInterface
    {
        public int $calls = 0;

        public function query(string $graphql, array $variables = []): array
        {
            $this->calls++;

            return FakeShopifyAdminGraphQlClient::wrapProductMediaStatus([
                [
                    'id' => 'gid://shopify/MediaImage/1',
                    'status' => $this->calls >= 2 ? 'READY' : 'UPLOADED',
                    'mediaContentType' => 'IMAGE',
                    'mediaErrors' => [],
                ],
            ]);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    app(ShopifyProductMediaProcessingWaiter::class)->waitForReady(
        'gid://shopify/Product/100',
        1,
    );

    expect($fake->calls)->toBeGreaterThanOrEqual(2);
});

it('throws when Shopify media status is FAILED', function (): void {
    $fake = new class implements ShopifyAdminGraphQlClientInterface
    {
        public function query(string $graphql, array $variables = []): array
        {
            return FakeShopifyAdminGraphQlClient::wrapProductMediaStatus([
                [
                    'id' => 'gid://shopify/MediaImage/99',
                    'status' => 'FAILED',
                    'mediaContentType' => 'IMAGE',
                    'mediaErrors' => [
                        [
                            'code' => 'DOWNLOAD_FAILED',
                            'message' => 'Media download failed',
                            'details' => 'Could not download image from originalSource URL.',
                        ],
                    ],
                ],
            ]);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    expect(fn () => app(ShopifyProductMediaProcessingWaiter::class)->waitForReady(
        'gid://shopify/Product/100',
        1,
    ))->toThrow(ShopifyGraphQlException::class, 'Could not download image');
});

it('no-ops when expected image count is zero', function (): void {
    $fake = new class implements ShopifyAdminGraphQlClientInterface
    {
        public int $calls = 0;

        public function query(string $graphql, array $variables = []): array
        {
            $this->calls++;

            return FakeShopifyAdminGraphQlClient::wrapProductMediaStatus([]);
        }
    };
    app()->instance(ShopifyAdminGraphQlClientInterface::class, $fake);

    app(ShopifyProductMediaProcessingWaiter::class)->waitForReady('gid://shopify/Product/100', 0);

    expect($fake->calls)->toBe(0);
});
