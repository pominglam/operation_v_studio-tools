<?php

namespace App\Providers;

use App\DAL\Products\EloquentProductRepository;
use App\DAL\Products\ProductRepository;
use App\DAL\PriceResearch\EloquentProductPriceQuoteRepository;
use App\DAL\PriceResearch\EloquentProductLookupRepository;
use App\DAL\PriceResearch\EloquentPriceResearchRunRepository;
use App\DAL\PriceResearch\ProductLookupRepository;
use App\DAL\PriceResearch\ProductPriceQuoteRepository;
use App\DAL\PriceResearch\PriceResearchRunRepository;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\PriceResearchService;
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
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);

        $this->app->bind(ProductLookupRepository::class, EloquentProductLookupRepository::class);
        $this->app->bind(ProductPriceQuoteRepository::class, EloquentProductPriceQuoteRepository::class);
        $this->app->bind(PriceResearchRunRepository::class, EloquentPriceResearchRunRepository::class);
        $this->app->singleton(ExternalHtmlClient::class);

        $this->app->tag([
            GundamHangarProvider::class,
            PandaHobbyProvider::class,
            CanadianGundamProvider::class,
            HobbyBeeProvider::class,
            HobbyWholesaleProvider::class,
            MeeplemartProvider::class,
            HobbySenseProvider::class,
        ], CompetitorPriceProvider::class);

        $this->app->bind(PriceResearchService::class, function ($app): PriceResearchService {
            return new PriceResearchService(
                $app->make(ProductLookupRepository::class),
                $app->make(ProductPriceQuoteRepository::class),
                $app->make(PriceResearchRunRepository::class),
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
