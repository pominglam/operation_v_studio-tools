<?php

declare(strict_types=1);

use App\Services\Products\Bandai\BandaiHtmlParser;

it('extracts pdp candidates from search html and picks best match', function (): void {
    $html = <<<'HTML'
    <html><body>
      <a href="/en-us/item/01_9999/">MG 1/100 GUNDAM SOMETHING ELSE</a>
      <a href="/en-us/item/01_6764/">MG 1/100 GUNDAM BARBATOS LUPUS</a>
      <a href="/en-us/news/abc">Not a pdp</a>
    </body></html>
    HTML;

    $parser = new BandaiHtmlParser;
    $cands = $parser->extractPdpCandidatesFromSearchHtml($html);
    expect($cands)->toHaveCount(2);

    $best = $parser->pickBestCandidate($cands, 'MG GUNDAM BARBATOS LUPUS');
    expect($best)->not->toBeNull();
    expect($best['url'])->toContain('/en-us/item/01_6764/');
});

it('parses pdp description, grade, series, yen price, launch date, and age', function (): void {
    $html = <<<'HTML'
    <html><body>
      <main>
      <h1>MG 1/100 GUNDAM BARBATOS LUPUS</h1>
      <span class="p-card__flatTit">MG [MASTER GRADE]</span>
      <a href="/en-us/series/tekketsu/">MOBILE SUIT GUNDAM IRON-BLOODED ORPHANS</a>
      <dl>
        <dt>Price</dt><dd>6,500 Yen</dd>
        <dt>Launch date</dt><dd>Nov 22, 2025 (Sat)</dd>
        <dt>Age</dt><dd>over the age of 15</dd>
      </dl>
      <h2>PRODUCTS INFO</h2>
      <div><p>Hello</p><p>World</p></div>
      <div class="pg-products__sliderThumbnailWrap">
        <a href="https://d2854ts9oov59b.cloudfront.net/hobby/en-usa/product/2025/11/foo/main.jpg?x=1">
          <img alt="MG 1/100 GUNDAM BARBATOS LUPUS" src="/thumbs/1.jpg" />
        </a>
        <a href="https://d2854ts9oov59b.cloudfront.net/hobby/en-usa/product/2025/11/foo/sub.png?x=1">
          <img alt="MG 1/100 GUNDAM BARBATOS LUPUS" src="/thumbs/2.png" />
        </a>
      </div>
      </main>
    </body></html>
    HTML;

    $parser = new BandaiHtmlParser;
    $parsed = $parser->parsePdp($html);

    expect($parsed['grade'])->toBe('MG');
    expect($parsed['series'])->toBe('MOBILE SUIT GUNDAM IRON-BLOODED ORPHANS');
    expect($parsed['yen_price'])->toBe(6500);
    expect((string) $parsed['launch_date']?->toDateString())->toBe('2025-11-22');
    expect($parsed['age_text'])->toBe('over the age of 15');
    expect($parsed['description_html'])->toContain('<p>Hello</p>');
    expect($parsed['image_urls'])->toHaveCount(2);
});
