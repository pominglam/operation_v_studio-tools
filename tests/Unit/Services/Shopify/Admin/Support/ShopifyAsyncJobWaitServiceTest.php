<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Services\Shopify\Admin\Support\ShopifyAsyncJobWaitService;
use Tests\TestCase;

uses(TestCase::class);

it('returns true when shopify job reports done', function (): void {
    $client = Mockery::mock(ShopifyAdminGraphQlClientInterface::class);
    $client->shouldReceive('query')->once()->andReturn([
        'data' => ['job' => ['id' => 'gid://shopify/Job/1', 'done' => true]],
    ]);

    $service = new ShopifyAsyncJobWaitService($client);

    expect($service->waitUntilDone('gid://shopify/Job/1', 2, 1))->toBeTrue();
});

it('returns false when shopify job never completes before timeout', function (): void {
    $client = Mockery::mock(ShopifyAdminGraphQlClientInterface::class);
    $client->shouldReceive('query')->andReturn([
        'data' => ['job' => ['id' => 'gid://shopify/Job/2', 'done' => false]],
    ]);

    $service = new ShopifyAsyncJobWaitService($client);

    expect($service->waitUntilDone('gid://shopify/Job/2', 1, 1))->toBeFalse();
});
