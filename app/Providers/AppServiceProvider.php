<?php

namespace App\Providers;

use App\DAL\Maintenance\EloquentMaintenanceNoteRepository;
use App\DAL\Maintenance\MaintenanceNoteRepository;
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
use App\DAL\Products\EloquentProductSellingPriceRepository;
use App\DAL\Products\ProductRepository;
use App\DAL\Products\ProductSellingPriceRepository;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Http\AliExpressScraperClient;
use App\Services\PriceResearch\PriceResearchService;
use App\Services\PriceResearch\Providers\ArgamaHobbyProvider;
use App\Services\PriceResearch\Providers\AliExpressProvider;
use App\Services\PriceResearch\Providers\CanadianGundamProvider;
use App\Services\PriceResearch\Providers\CompetitorPriceProvider;
use App\Services\PriceResearch\Providers\GundamHangarProvider;
use App\Services\PriceResearch\Providers\HobbyBeeProvider;
use App\Services\PriceResearch\Providers\HobbySenseProvider;
use App\Services\PriceResearch\Providers\HobbyWholesaleProvider;
use App\Services\PriceResearch\Providers\MeeplemartProvider;
use App\Services\PriceResearch\Providers\PandaHobbyProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MaintenanceNoteRepository::class, EloquentMaintenanceNoteRepository::class);

        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(ProductSellingPriceRepository::class, EloquentProductSellingPriceRepository::class);

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

        $this->app->tag([
            AliExpressProvider::class,
            GundamHangarProvider::class,
            PandaHobbyProvider::class,
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
