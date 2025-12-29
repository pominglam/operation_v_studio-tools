<?php

declare(strict_types=1);

use App\Models\Product;
use App\DAL\Products\ProductExternalContentRepository;
use App\Models\ProductExternalContent;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Providers\AbstractSearchProvider;
use App\Services\PriceResearch\Support\HtmlPriceParser;

it('allows small wording differences in title matching (up to 2 missing tokens)', function (): void {
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

        public function matches(string $html, Product $product): bool
        {
            return $this->htmlLikelyMatchesProduct($html, $product);
        }
    };

    $p = new Product;
    $p->description = 'HG 1/144 Gundam Astray Blue Frame';

    // Missing "Frame" but still obviously the same product.
    $html = '<h1>HG 1/144 Astray Blue</h1>';

    expect($provider->matches($html, $p))->toBeTrue();
});

it('matches short-but-meaningful tokens like RG + 144 + God (Argama example)', function (): void {
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

        public function matches(string $html, Product $product): bool
        {
            return $this->htmlLikelyMatchesProduct($html, $product);
        }
    };

    $p = new Product;
    $p->description = 'RG 1/144 GOD GUNDAM';

    $html = '<h1>RG GF13-017NJII God Gundam (Bandai Real Grade 1/144)</h1>';

    expect($provider->matches($html, $p))->toBeTrue();
});

it('does not match when tokens only appear outside the PDP title (avoids false positives)', function (): void {
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

        public function matches(string $html, Product $product): bool
        {
            return $this->htmlLikelyMatchesProduct($html, $product);
        }
    };

    $p = new Product;
    $p->description = 'RG 1/144 GOD GUNDAM';

    // Title is unrelated (e.g. gift card), but the page contains the keywords in a recommendation widget.
    $html = '<h1>Argama Hobby Gift Card</h1><div class="recommendations">RG God Gundam 1/144</div>';

    expect($provider->matches($html, $p))->toBeFalse();
});

it('does not match based only on grade+scale tokens (RG 1/144) without the product name', function (): void {
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

        public function matches(string $html, Product $product): bool
        {
            return $this->htmlLikelyMatchesProduct($html, $product);
        }
    };

    $p = new Product;
    $p->description = 'RG 1/144 GOD GUNDAM';

    $html = '<h1>Gundam Decal 131 RG 1/144 Zeong</h1>';

    expect($provider->matches($html, $p))->toBeFalse();
});
