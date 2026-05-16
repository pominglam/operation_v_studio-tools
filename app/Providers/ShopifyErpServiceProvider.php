<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Shopify\ShopifyAdminAccessTokenProviderInterface;
use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Services\Shopify\Admin\Auth\PersistedShopifyAdminAccessTokenProvider;
use App\Services\Shopify\Admin\ShopifyAdminGraphQlClient;
use App\Services\Shopify\Admin\Sync\ShopifyCollectionSyncRunner;
use App\Services\Shopify\Admin\Sync\ShopifyCustomerSyncRunner;
use App\Services\Shopify\Admin\Sync\ShopifyErpSyncCoordinator;
use App\Services\Shopify\Admin\Sync\ShopifyInventoryLevelSyncRunner;
use App\Services\Shopify\Admin\Sync\ShopifyLocationSyncRunner;
use App\Services\Shopify\Admin\Sync\ShopifyOrderSyncRunner;
use App\Services\Shopify\Admin\Sync\ShopifyProductCatalogSyncRunner;
use Illuminate\Support\ServiceProvider;

final class ShopifyErpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShopifyAdminAccessTokenProviderInterface::class, PersistedShopifyAdminAccessTokenProvider::class);

        $this->app->singleton(ShopifyAdminGraphQlClientInterface::class, ShopifyAdminGraphQlClient::class);

        $this->app->singleton(ShopifyLocationSyncRunner::class, function (): ShopifyLocationSyncRunner {
            return new ShopifyLocationSyncRunner(max(5, min(250, (int) config('shopify.graphql_page_size'))));
        });
        $this->app->singleton(ShopifyProductCatalogSyncRunner::class, function (): ShopifyProductCatalogSyncRunner {
            return new ShopifyProductCatalogSyncRunner(max(5, min(250, (int) config('shopify.graphql_page_size'))));
        });
        $this->app->singleton(ShopifyInventoryLevelSyncRunner::class, function (): ShopifyInventoryLevelSyncRunner {
            return new ShopifyInventoryLevelSyncRunner(max(5, min(250, (int) config('shopify.graphql_page_size'))));
        });
        $this->app->singleton(ShopifyOrderSyncRunner::class, function (): ShopifyOrderSyncRunner {
            return new ShopifyOrderSyncRunner(max(5, min(250, (int) config('shopify.graphql_page_size'))));
        });
        $this->app->singleton(ShopifyCustomerSyncRunner::class, function (): ShopifyCustomerSyncRunner {
            return new ShopifyCustomerSyncRunner(max(5, min(250, (int) config('shopify.graphql_page_size'))));
        });
        $this->app->singleton(ShopifyCollectionSyncRunner::class, function (): ShopifyCollectionSyncRunner {
            return new ShopifyCollectionSyncRunner(max(5, min(250, (int) config('shopify.graphql_page_size'))));
        });

        $this->app->bind(ShopifyErpSyncCoordinator::class, function ($app): ShopifyErpSyncCoordinator {
            return new ShopifyErpSyncCoordinator(
                $app->make(ShopifyAdminGraphQlClientInterface::class),
                [
                    $app->make(ShopifyLocationSyncRunner::class),
                    $app->make(ShopifyProductCatalogSyncRunner::class),
                    $app->make(ShopifyInventoryLevelSyncRunner::class),
                    $app->make(ShopifyOrderSyncRunner::class),
                    $app->make(ShopifyCustomerSyncRunner::class),
                    $app->make(ShopifyCollectionSyncRunner::class),
                ],
            );
        });
    }
}
