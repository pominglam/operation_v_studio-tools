<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Providers\CanadianGundamProvider;
use App\Services\PriceResearch\Support\HtmlPriceParser;
use App\DAL\Products\ProductExternalContentRepository;
use App\Models\ProductExternalContent;

/**
 * Call a protected method without adding production test hooks.
 */
function cgMatches(CanadianGundamProvider $provider, string $html, Product $product): bool
{
    /** @var callable $fn */
    $fn = \Closure::bind(
        static fn (string $h, Product $p): bool => $provider->htmlLikelyMatchesProduct($h, $p),
        null,
        $provider,
    );

    return (bool) $fn($html, $product);
}

it('does not match a CanadianGundam PDP when the grade differs (HG vs MG)', function (): void {
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

    $provider = new CanadianGundamProvider(new ExternalHtmlClient, new HtmlPriceParser, $contents);

    $p = new Product;
    $p->description = 'HGBF 1/144 Sengoku Astray Gundam';

    $html = '<html><body><h1>MG Sengoku Gundam Astray</h1></body></html>';

    expect(cgMatches($provider, $html, $p))->toBeFalse();
});

it('does not match when barcode matches but the grade differs (HG vs MG)', function (): void {
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

    $provider = new CanadianGundamProvider(new ExternalHtmlClient, new HtmlPriceParser, $contents);

    $p = new Product;
    $p->description = 'HGBF 1/144 Sengoku Astray Gundam';
    $p->barcode = '4573102661364';

    $html = '<html><body><h1>MG Sengoku Gundam Astray 4573102661364</h1></body></html>';

    expect(cgMatches($provider, $html, $p))->toBeFalse();
});

it('does not match when scale differs (1/144 vs 1/100)', function (): void {
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

    $provider = new CanadianGundamProvider(new ExternalHtmlClient, new HtmlPriceParser, $contents);

    $p = new Product;
    $p->description = 'HGBF 1/144 Sengoku Astray Gundam';

    $html = '<html><body><h1>HG 1/100 Sengoku Astray Gundam</h1></body></html>';

    expect(cgMatches($provider, $html, $p))->toBeFalse();
});

it('matches when the grade group aligns (HG family)', function (): void {
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

    $provider = new CanadianGundamProvider(new ExternalHtmlClient, new HtmlPriceParser, $contents);

    $p = new Product;
    $p->description = 'HGBF 1/144 Sengoku Astray Gundam';

    $html = '<html><body><h1>HG Sengoku Astray Gundam</h1></body></html>';

    expect(cgMatches($provider, $html, $p))->toBeTrue();
});
