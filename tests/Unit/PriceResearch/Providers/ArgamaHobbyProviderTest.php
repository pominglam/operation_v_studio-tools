<?php

declare(strict_types=1);

use App\DAL\Products\ProductExternalContentRepository;
use App\Models\ProductExternalContent;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Providers\ArgamaHobbyProvider;
use App\Services\PriceResearch\Support\HtmlPriceParser;

it('exposes correct site key', function (): void {
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

    $provider = new ArgamaHobbyProvider(new ExternalHtmlClient, new HtmlPriceParser, $contents);

    expect($provider->siteKey())->toBe('argama_hobby');
});

it('prefers candidate URLs that look like the intended product (RG God Gundam) over decals/gift cards', function (): void {
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

    $provider = new ArgamaHobbyProvider(new ExternalHtmlClient, new HtmlPriceParser, $contents);

    $p = new \App\Models\Product;
    $p->description = 'RG 1/144 GOD GUNDAM';

    $links = [
        'https://argamahobby.com/products/argama-hobby-gift-card',
        'https://argamahobby.com/products/gundam-decal-131-rg-1-144-zeong',
        'https://argamahobby.com/products/rg-gf13-017njii-god-gundam-bandai-real-grade-1-144',
    ];

    /** @var callable $fn */
    $fn = \Closure::bind(
        static fn (array $l) => $provider->orderCandidateProductUrls($p, $l),
        null,
        $provider,
    );

    /** @var array<int, string> $ordered */
    $ordered = $fn($links);

    expect($ordered[0])->toContain('god-gundam');
});
