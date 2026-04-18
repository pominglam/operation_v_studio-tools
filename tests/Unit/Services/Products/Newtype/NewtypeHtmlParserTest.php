<?php

declare(strict_types=1);

use App\Services\Products\Newtype\NewtypeHtmlParser;

it('extracts search candidates from Newtype search HTML', function (): void {
    $html = <<<'HTML'
    <html><body>
      <a href="/p/AAA/h/hgac-174-wing-gundam-zero">Bandai HGAC 174 Wing Gundam Zero</a>
      <a href="https://newtype.us/p/BBB/h/rg-god-gundam">RG God Gundam</a>
      <a href="/collections/all">Ignore collections</a>
    </body></html>
    HTML;

    $p = new NewtypeHtmlParser;
    $cands = $p->extractSearchCandidatesFromSearchHtml($html);

    expect($cands)->toHaveCount(2);
    expect($cands[0]['url'])->toBe('https://newtype.us/p/AAA/h/hgac-174-wing-gundam-zero');
    expect($cands[0]['title'])->toBe('Bandai HGAC 174 Wing Gundam Zero');
});

it('extracts gallery-scoped image URLs from Newtype PDP HTML', function (): void {
    $html = <<<'HTML'
    <html><body>
      <img src="https://cdn.example.com/outside.jpg" />

      <div class="pt-square relative w-full overflow-hidden">
        <div class="absolute" style="background-image:url('https://cdn.shopify.com/s/files/1/1/products/box.png?v=1')"></div>
        <div class="absolute" style="background-image:url(https://cdn.shopify.com/s/files/1/1/products/other.jpg?v=2)"></div>
      </div>

      <div class="centered">
        <img alt="box art" src="https://cdn.shopify.com/s/files/1/1/products/explicit.jpg?v=3" />
      </div>
    </body></html>
    HTML;

    $p = new NewtypeHtmlParser;
    $urls = $p->extractImageUrlsFromPdpHtml($html);

    // "box art" should be first even if it's outside the gallery container.
    expect($urls[0])->toBe('https://cdn.shopify.com/s/files/1/1/products/explicit.jpg?v=3');

    expect($urls)->toContain('https://cdn.shopify.com/s/files/1/1/products/explicit.jpg?v=3');
    expect($urls)->toContain('https://cdn.shopify.com/s/files/1/1/products/box.png?v=1');
    expect($urls)->toContain('https://cdn.shopify.com/s/files/1/1/products/other.jpg?v=2');
    expect($urls)->not->toContain('https://cdn.example.com/outside.jpg');
});

it('extracts description + facts (scale/line/brand/series) from Newtype PDP HTML', function (): void {
    $html = <<<'HTML'
    <html><head>
      <meta property="og:title" content="Bandai HGAC 174 Wing Gundam Zero - Newtype" />
      <script type="application/ld+json">
        {"@type":"Product","description":"<p>Desc <strong>HTML</strong></p>"}
      </script>
    </head><body>
      <table><tbody>
        <tr><td class="pr-5 w-1/4">Scale</td><td>1/144</td></tr>
        <tr><td>Line</td><td><a href="/t/modelkit/line/hg"> HG - High Grade </a></td></tr>
        <tr><td class="pr-5 w-1/4">Brand</td><td><a href="/b/gundam"> Mobile Suit Gundam </a></td></tr>
        <tr><td class="pr-5 w-1/4">Series</td><td><a href="/t/modelkit/series/gundamwing"> Gundam Wing </a></td></tr>
      </tbody></table>
    </body></html>
    HTML;

    $p = new NewtypeHtmlParser;
    $out = $p->extractDescriptionAndFactsFromPdpHtml($html);

    expect($out['title'])->toContain('Wing Gundam Zero');
    expect($out['description_html'])->toBe('<p>Desc <strong>HTML</strong></p>');
    expect($out['scale'])->toBe('1/144');
    expect($out['line'])->toBe('HG - High Grade');
    expect($out['brand'])->toBe('Mobile Suit Gundam');
    expect($out['series'])->toBe('Gundam Wing');
});
