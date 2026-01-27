<?php

declare(strict_types=1);

use App\Models\Product;
use App\DAL\Products\ProductExternalContentRepository;
use App\Models\ProductExternalContent;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Providers\AbstractSearchProvider;
use App\Services\PriceResearch\Support\HtmlPriceParser;

it('prefers SKU over barcode for the default search term', function (): void {
    // No network calls are made in this test; we only validate search-term selection.
    $http = new ExternalHtmlClient;
    $parser = new HtmlPriceParser;
    $contents = new class implements ProductExternalContentRepository
    {
        public function upsertForProduct(int $productId, string $source, ?string $title, ?string $descriptionHtml, ?array $attributes, ?string $sourceUrl = null): ProductExternalContent
        {
            return new ProductExternalContent;
        }

        public function findForProduct(int $productId, string $source): ?ProductExternalContent
        {
            return null;
        }

        public function listForProduct(int $productId): array
        {
            return [];
        }

        public function updateSourceUrl(int $id, ?string $sourceUrl): void
        {
            //
        }
    };

    $provider = new class($http, $parser, $contents) extends AbstractSearchProvider
    {
        public function siteKey(): string
        {
            return 'test';
        }

        public function siteName(): string
        {
            return 'Test';
        }

        protected function baseUrl(): string
        {
            return 'https://example.com';
        }

        public function exposedSearchTermForProduct(Product $product): ?string
        {
            return $this->searchTermForProduct($product);
        }
    };

    $p = new Product;
    $p->sku = '5068840';
    $p->barcode = '4573102688408';
    $p->description = 'HG 1/144 GUNDAM HEAVYARMS CUSTOM';

    expect($provider->exposedSearchTermForProduct($p))->toBe('5068840');
});

it('includes the product name as a fallback search term (URL-encoded by search URLs)', function (): void {
    $http = new ExternalHtmlClient;
    $parser = new HtmlPriceParser;
    $contents = new class implements ProductExternalContentRepository
    {
        public function upsertForProduct(int $productId, string $source, ?string $title, ?string $descriptionHtml, ?array $attributes, ?string $sourceUrl = null): ProductExternalContent
        {
            return new ProductExternalContent;
        }

        public function findForProduct(int $productId, string $source): ?ProductExternalContent
        {
            return null;
        }

        public function listForProduct(int $productId): array
        {
            return [];
        }

        public function updateSourceUrl(int $id, ?string $sourceUrl): void
        {
            //
        }
    };

    $provider = new class($http, $parser, $contents) extends AbstractSearchProvider
    {
        public function siteKey(): string
        {
            return 'test';
        }

        public function siteName(): string
        {
            return 'Test';
        }

        protected function baseUrl(): string
        {
            return 'https://example.com';
        }

        /** @return array<int, string> */
        public function exposedSearchTermsForProduct(Product $product): array
        {
            return $this->searchTermsForProduct($product);
        }
    };

    $p = new Product;
    $p->sku = '5068840';
    $p->barcode = '4573102688408';
    $p->description = 'HG 1/144 GUNDAM HEAVYARMS CUSTOM';

    $terms = $provider->exposedSearchTermsForProduct($p);

    expect($terms)->toContain('5068840');
    expect($terms)->toContain('4573102688408');
    expect($terms)->toContain('HG 1/144 GUNDAM HEAVYARMS CUSTOM');
});
