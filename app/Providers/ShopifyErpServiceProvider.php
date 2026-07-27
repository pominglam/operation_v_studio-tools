<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Shopify\ShopifyAdminAccessTokenProviderInterface;
use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\DAL\Shopify\EloquentShopifySyncStateRepository;
use App\DAL\Shopify\ShopifySyncStateRepository;
use App\Services\Shopify\Admin\Auth\PersistedShopifyAdminAccessTokenProvider;
use App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileService;
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

        $this->app->singleton(ShopifySyncStateRepository::class, EloquentShopifySyncStateRepository::class);

        $pageSize = max(5, min(250, (int) config('shopify.graphql_page_size')));

        $this->app->singleton(ShopifyOrderReconcileService::class, function ($app) use ($pageSize): ShopifyOrderReconcileService {
            return new ShopifyOrderReconcileService(
                $app->make(ShopifyAdminGraphQlClientInterface::class),
                $app->make(\App\Services\Shopify\Admin\Orders\ShopifyOrderUpsertService::class),
                $app->make(ShopifySyncStateRepository::class),
                $pageSize,
            );
        });

        $this->app->singleton(ShopifyLocationSyncRunner::class, function () use ($pageSize): ShopifyLocationSyncRunner {
            return new ShopifyLocationSyncRunner($pageSize);
        });
        $this->app->singleton(ShopifyProductCatalogSyncRunner::class, function ($app) use ($pageSize): ShopifyProductCatalogSyncRunner {
            return new ShopifyProductCatalogSyncRunner(
                $pageSize,
                $app->make(\App\Services\Shopify\Admin\Sync\ShopifyProductCatalogMirrorUpsertService::class),
            );
        });
        $this->app->singleton(ShopifyInventoryLevelSyncRunner::class, function () use ($pageSize): ShopifyInventoryLevelSyncRunner {
            return new ShopifyInventoryLevelSyncRunner($pageSize);
        });
        $this->app->singleton(ShopifyOrderSyncRunner::class, function ($app) use ($pageSize): ShopifyOrderSyncRunner {
            return new ShopifyOrderSyncRunner(
                $pageSize,
                $app->make(\App\Services\Shopify\Admin\Orders\ShopifyOrderUpsertService::class),
            );
        });
        $this->app->singleton(ShopifyCustomerSyncRunner::class, function () use ($pageSize): ShopifyCustomerSyncRunner {
            return new ShopifyCustomerSyncRunner($pageSize);
        });
        $this->app->singleton(ShopifyCollectionSyncRunner::class, function () use ($pageSize): ShopifyCollectionSyncRunner {
            return new ShopifyCollectionSyncRunner($pageSize);
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
