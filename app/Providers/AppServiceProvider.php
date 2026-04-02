<?php

namespace App\Providers;

use App\DAL\Maintenance\EloquentMaintenanceNoteRepository;
use App\DAL\Maintenance\MaintenanceNoteRepository;
use App\DAL\Maintenance\DatabaseBackupRepository;
use App\DAL\Maintenance\EloquentDatabaseBackupRepository;
use App\DAL\PriceResearch\EloquentPriceResearchQuoteReportRepository;
use App\DAL\PriceResearch\EloquentPriceResearchRunLogRepository;
use App\DAL\PriceResearch\EloquentPriceResearchRunRepository;
use App\DAL\PriceResearch\EloquentProductLookupRepository;
use App\DAL\PriceResearch\EloquentProductPriceQuoteRepository;
use App\DAL\PriceResearch\PriceResearchQuoteReportRepository;
use App\DAL\PriceResearch\PriceResearchRunLogRepository;
use App\DAL\PriceResearch\PriceResearchRunRepository;
use App\DAL\PriceResearch\ProductLookupRepository;
use App\DAL\PriceResearch\ProductPriceQuoteRepository;
use App\DAL\Products\EloquentProductRepository;
use App\DAL\Products\EloquentProductExternalAssetRepository;
use App\DAL\Products\EloquentProductExternalContentRepository;
use App\DAL\Products\EloquentProductSellingPriceRepository;
use App\DAL\Products\ProductRepository;
use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductSellingPriceRepository;
use App\DAL\InventoryChecks\EloquentInventoryCheckRepository;
use App\DAL\InventoryChecks\InventoryCheckRepository;
use App\DAL\Inventory\EloquentInventoryRepository;
use App\DAL\Inventory\InventoryRepository;
use App\DAL\Jobs\EloquentJobBatchItemRepository;
use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\RuntimeSettings\EloquentRuntimeSettingRepository;
use App\DAL\RuntimeSettings\RuntimeSettingRepository;
use App\DAL\PurchaseOrders\EloquentPurchaseOrderRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\DAL\TcgEvents\EloquentTcgEventRepository;
use App\DAL\TcgEvents\TcgEventRepository;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Http\AliExpressScraperClient;
use App\Services\PriceResearch\PriceResearchService;
use App\Services\PriceResearch\Providers\ArgamaHobbyProvider;
use App\Services\PriceResearch\Providers\AliExpressProvider;
use App\Services\PriceResearch\Providers\CanadaComputersProvider;
use App\Services\PriceResearch\Providers\CanadianGundamProvider;
use App\Services\PriceResearch\Providers\CompetitorPriceProvider;
use App\Services\PriceResearch\Providers\GundamHangarProvider;
use App\Services\PriceResearch\Providers\HobbyBeeProvider;
use App\Services\PriceResearch\Providers\HobbySenseProvider;
use App\Services\PriceResearch\Providers\HobbyWholesaleProvider;
use App\Services\PriceResearch\Providers\MeeplemartProvider;
use App\Services\PriceResearch\Providers\PandaHobbyProvider;
use App\Services\Products\Http\PlamodScraper;
use App\Services\Products\Http\PlamodScraperClient;
use App\Services\Products\Hlj\HljContentSync;
use App\Services\Products\Hlj\HljContentSyncService;
use App\Services\Maintenance\DatabaseBackupManager;
use App\Services\Maintenance\DatabaseBackupManagerService;
use App\Services\Maintenance\DatabaseRestore;
use App\Services\Maintenance\DatabaseRestoreService;
use App\Services\Maintenance\CloudflareQuickTunnelVerifier as MaintenanceQuickTunnelVerifier;
use App\Services\Shopify\CloudflaredTunnel;
use App\Services\Shopify\CloudflaredTunnelService;
use App\Services\Shopify\CloudflareQuickTunnelVerifier;
use App\Services\TcgEvents\Providers\BandaiTcgPlusApi;
use App\Services\TcgEvents\Providers\HttpBandaiTcgPlusApi;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MaintenanceNoteRepository::class, EloquentMaintenanceNoteRepository::class);
        $this->app->bind(DatabaseBackupRepository::class, EloquentDatabaseBackupRepository::class);
        $this->app->bind(DatabaseBackupManager::class, DatabaseBackupManagerService::class);
        $this->app->bind(DatabaseRestore::class, DatabaseRestoreService::class);

        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(ProductSellingPriceRepository::class, EloquentProductSellingPriceRepository::class);
        $this->app->bind(ProductExternalContentRepository::class, EloquentProductExternalContentRepository::class);
        $this->app->bind(ProductExternalAssetRepository::class, EloquentProductExternalAssetRepository::class);
        $this->app->bind(JobBatchItemRepository::class, EloquentJobBatchItemRepository::class);
        $this->app->bind(RuntimeSettingRepository::class, EloquentRuntimeSettingRepository::class);
        $this->app->bind(InventoryCheckRepository::class, EloquentInventoryCheckRepository::class);
        $this->app->bind(PurchaseOrderRepository::class, EloquentPurchaseOrderRepository::class);
        $this->app->bind(InventoryRepository::class, EloquentInventoryRepository::class);
        $this->app->bind(TcgEventRepository::class, EloquentTcgEventRepository::class);

        $this->app->bind(ProductLookupRepository::class, EloquentProductLookupRepository::class);
        $this->app->bind(ProductPriceQuoteRepository::class, EloquentProductPriceQuoteRepository::class);
        $this->app->bind(PriceResearchRunRepository::class, EloquentPriceResearchRunRepository::class);
        $this->app->bind(PriceResearchRunLogRepository::class, EloquentPriceResearchRunLogRepository::class);
        $this->app->bind(PriceResearchQuoteReportRepository::class, EloquentPriceResearchQuoteReportRepository::class);
        $this->app->singleton(ExternalHtmlClient::class);
        $this->app->singleton(AliExpressScraperClient::class, function (): AliExpressScraperClient {
            $url = (string) env('ALIEXPRESS_SCRAPER_URL', 'http://aliexpress_scraper:3000');
            return new AliExpressScraperClient($url);
        });

        $this->app->singleton(PlamodScraperClient::class, function (): PlamodScraperClient {
            $url = (string) env('PLAMOD_SCRAPER_URL', 'http://plamod_scraper:3001');

            return new PlamodScraperClient($url);
        });
        $this->app->bind(PlamodScraper::class, PlamodScraperClient::class);
        $this->app->bind(HljContentSync::class, HljContentSyncService::class);
        $this->app->singleton(CloudflareQuickTunnelVerifier::class);
        $this->app->singleton(MaintenanceQuickTunnelVerifier::class);
        $this->app->bind(CloudflaredTunnel::class, function ($app): CloudflaredTunnelService {
            return new CloudflaredTunnelService($app->make(CloudflareQuickTunnelVerifier::class));
        });
        $this->app->bind(BandaiTcgPlusApi::class, HttpBandaiTcgPlusApi::class);

        $this->app->tag([
            AliExpressProvider::class,
            GundamHangarProvider::class,
            PandaHobbyProvider::class,
            CanadaComputersProvider::class,
            CanadianGundamProvider::class,
            HobbyBeeProvider::class,
            HobbyWholesaleProvider::class,
            MeeplemartProvider::class,
            HobbySenseProvider::class,
            ArgamaHobbyProvider::class,
        ], CompetitorPriceProvider::class);

        $this->app->bind(PriceResearchService::class, function ($app): PriceResearchService {
            return new PriceResearchService(
                $app->make(ProductLookupRepository::class),
                $app->make(ProductPriceQuoteRepository::class),
                $app->make(PriceResearchRunRepository::class),
                $app->make(PriceResearchRunLogRepository::class),
                $app->tagged(CompetitorPriceProvider::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
