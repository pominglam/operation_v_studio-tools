<?php

declare(strict_types=1);

use App\Services\Products\GundamPlanet\GundamPlanetHtmlParser;

it('extracts only <product-gallery> images and ignores images outside', function (): void {
    $html = <<<HTML
    <html><body>
      <img src="https://cdn.example.com/outside.jpg" />
      <product-gallery>
        <div>
          <img src="/cdn/shop/files/a.jpg" />
          <img srcset="/cdn/shop/files/b_200.jpg 200w, /cdn/shop/files/b_800.jpg 800w" />
          <picture>
            <source srcset="//cdn.shopify.com/files/c_100.jpg 100w, //cdn.shopify.com/files/c_900.jpg 900w" />
            <img data-src="https://cdn.shopify.com/files/c_fallback.jpg" />
          </picture>
        </div>
      </product-gallery>
      <img src="https://cdn.example.com/outside2.jpg" />
    </body></html>
    HTML;

    $parser = new GundamPlanetHtmlParser();
    $urls = $parser->extractImageUrlsFromPdpHtml($html);

    expect($urls)->toHaveCount(3);
    // Only inside gallery, normalized:
    expect($urls[0])->toBe('https://www.gundamplanet.com/cdn/shop/files/a.jpg');
    expect($urls[1])->toBe('https://www.gundamplanet.com/cdn/shop/files/b_800.jpg');
    expect($urls[2])->toBe('https://cdn.shopify.com/files/c_900.jpg');
    expect(implode(' ', $urls))->not->toContain('outside');
});

it('extracts product candidates from search html and ignores non-product links', function (): void {
    $html = <<<HTML
    <html><body>
      <a href="/blogs/news/abc">Blog</a>
      <a href="/products/rg-god-gundam">RG God Gundam (Burning Gundam)</a>
      <a href="https://www.gundamplanet.com/products/rg-god-gundam">Duplicate absolute</a>
      <a href="/collections/gundam">Collection</a>
      <a href="/products/rg-00-qan-t"><img alt="RG 00 QAN[T]" /></a>
    </body></html>
    HTML;

    $parser = new GundamPlanetHtmlParser();
    $cands = $parser->extractSearchCandidatesFromSearchHtml($html);

    expect($cands)->toHaveCount(2);
    expect($cands[0]['url'])->toBe('https://www.gundamplanet.com/products/rg-god-gundam');
    expect($cands[0]['title'])->toContain('RG God Gundam');
    expect($cands[1]['url'])->toBe('https://www.gundamplanet.com/products/rg-00-qan-t');
    expect($cands[1]['title'])->toBe('RG 00 QAN[T]');
});

