<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Providers\AbstractSearchProvider;
use App\Services\PriceResearch\Support\HtmlPriceParser;

it('allows small wording differences in title matching (up to 2 missing tokens)', function (): void {
    $http = new ExternalHtmlClient();
    $parser = new HtmlPriceParser();

    $provider = new class($http, $parser) extends AbstractSearchProvider {
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

    $p = new Product();
    $p->description = 'HG 1/144 Gundam Astray Blue Frame';

    // Missing "Frame" but still obviously the same product.
    $html = '<h1>HG 1/144 Astray Blue</h1>';

    expect($provider->matches($html, $p))->toBeTrue();
});


