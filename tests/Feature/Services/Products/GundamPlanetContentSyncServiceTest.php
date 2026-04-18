<?php

declare(strict_types=1);

use App\DAL\Products\ProductExternalAssetRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\Products\GundamPlanet\GundamPlanetContentSyncService;
use App\Services\Products\GundamPlanet\GundamPlanetHtmlParser;
use App\Services\Products\Hlj\HljHtmlParser;
use App\Services\Products\Hlj\HljPdpResolverService;
use App\Services\Products\ProductPdpSearchTermsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('syncs gundamplanet images strictly from <product-gallery> and replaces assets', function (): void {
    Storage::fake('local');

    $searchHtml = <<<'HTML'
    <html><body>
      <a href="/products/rg-god-gundam">RG God Gundam (Burning Gundam)</a>
      <a href="/products/rg-00-qan-t">RG 00 QAN[T]</a>
    </body></html>
    HTML;

    $pdpHtml = <<<'HTML'
    <html><body>
      <img src="https://cdn.example.com/outside.jpg" />
      <product-gallery>
        <img src="https://cdn.shopify.com/files/gp1.jpg" />
        <img srcset="https://cdn.shopify.com/files/gp2_200.jpg 200w, https://cdn.shopify.com/files/gp2_900.jpg 900w" />
      </product-gallery>
      <img src="https://cdn.example.com/outside2.jpg" />
    </body></html>
    HTML;

    Http::fake(function (\Illuminate\Http\Client\Request $req) use ($searchHtml, $pdpHtml) {
        $url = $req->url();
        if (str_contains($url, 'www.gundamplanet.com/search?')) {
            return Http::response($searchHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'www.gundamplanet.com/products/rg-god-gundam')) {
            return Http::response($pdpHtml, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        if (str_contains($url, 'cdn.shopify.com/files/gp1.jpg')) {
            return Http::response('img1', 200, ['Content-Type' => 'image/jpeg']);
        }
        if (str_contains($url, 'cdn.shopify.com/files/gp2_900.jpg')) {
            return Http::response('img2', 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('not found', 404);
    });

    $captured = null;
    $assetsRepo = new class($captured) implements ProductExternalAssetRepository
    {
        /** @var array<int, array<string, mixed>>|null */
        public ?array $capturedRows = null;

        public function __construct(&$captured)
        {
            // keep signature stable
        }

        public function replaceForProduct(int $productId, string $source, array $assets): array
        {
            expect($productId)->toBe(123);
            expect($source)->toBe('gundamplanet');
            $this->capturedRows = $assets;

            return [];
        }

        public function listForProduct(int $productId, string $source): array
        {
            return [];
        }

        public function listAllForProduct(int $productId): array
        {
            return [];
        }

        public function updateSortOrders(array $assetIdToSortOrder): void {}

        public function findById(int $id): ?\App\Models\ProductExternalAsset
        {
            return null;
        }

        public function setShopifyEnabled(int $id, bool $enabled): void {}

        public function createForProduct(int $productId, string $source, array $assets): array
        {
            return [];
        }
    };

    $product = new Product;
    $product->id = 123;
    $product->uuid = 'p-uuid';
    $product->sku = 'RG-GOD';
    $product->barcode = null;
    $product->description = 'RG GOD GUNDAM';

    $http = new ExternalHtmlClient;
    $hlj = new HljPdpResolverService($http, new HljHtmlParser);
    $terms = new ProductPdpSearchTermsService($hlj);
    $service = new GundamPlanetContentSyncService($http, new GundamPlanetHtmlParser, $assetsRepo, $terms);

    $service->syncForProduct($product);

    expect($assetsRepo->capturedRows)->not->toBeNull();
    expect($assetsRepo->capturedRows)->toHaveCount(2);
    expect($assetsRepo->capturedRows[0]['origin_url'])->toBe('https://cdn.shopify.com/files/gp1.jpg');
    expect($assetsRepo->capturedRows[1]['origin_url'])->toBe('https://cdn.shopify.com/files/gp2_900.jpg');

    Storage::disk('local')->assertExists('gundamplanet/images/RG-GOD/gundamplanet-RG-GOD-1.jpg');
    Storage::disk('local')->assertExists('gundamplanet/images/RG-GOD/gundamplanet-RG-GOD-2.jpg');
});
