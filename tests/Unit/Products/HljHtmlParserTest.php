<?php

declare(strict_types=1);

use App\Services\Products\Hlj\HljHtmlParser;

it('extracts HLJ image urls from JSON-LD and meta tags and normalizes them', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <meta property="og:image" content="//www.hlj.com/media/catalog/product/a/b/ab123_main.jpg" />
    <meta name="twitter:image" content="/media/catalog/product/a/b/ab123_alt.png" />
    <script type="application/ld+json">
      {
        "@context": "https://schema.org",
        "@type": "Product",
        "name": "Test Product",
        "image": [
          "https://www.hlj.com/media/catalog/product/a/b/ab123_main.jpg",
          "https://www.hlj.com/media/catalog/product/a/b/ab123_02.webp"
        ]
      }
    </script>
  </head>
  <body>
    <img src="https://www.hlj.com/media/wysiwyg/banner/sale.jpg" />
    <img class="product-image" data-zoom-image="https://www.hlj.com/media/catalog/product/a/b/ab123_03.jpg" />
    <img src="https://www.hlj.com/images/icon.svg" />
  </body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $urls = $parser->extractImageUrls($html);

    expect($urls)->toContain('https://www.hlj.com/media/catalog/product/a/b/ab123_main.jpg');
    expect($urls)->toContain('https://www.hlj.com/media/catalog/product/a/b/ab123_02.webp');
    expect($urls)->toContain('https://www.hlj.com/media/catalog/product/a/b/ab123_03.jpg');

    // normalized from // and / URLs
    expect($urls)->toContain('https://www.hlj.com/media/catalog/product/a/b/ab123_alt.png');

    // Should not include non-product imagery when product images are available.
    expect($urls)->not->toContain('https://www.hlj.com/media/wysiwyg/banner/sale.jpg');
});

it('extracts images from the fotorama gallery and filters non-product slides (shipping logos)', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <head></head>
  <body>
    <div class="fotorama fotorama1688647073468" data-nav="thumbs" data-allowfullscreen="true">
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/ban12345_01.jpg" />
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/ban12345_02.jpg" />
      <img class="fotorama__img" src="https://www.hlj.com/media/wysiwyg/shipping/fedex.png" />
      <img class="fotorama__img" src="https://www.hlj.com/productimages/shipping/dhl.jpg" />
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/fedex.jpg" />
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/paypal.png" />
    </div>
  </body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $urls = $parser->extractImageUrls($html);

    expect($urls)->toContain('https://www.hlj.com/productimages/ban/ban12345_01.jpg');
    expect($urls)->toContain('https://www.hlj.com/productimages/ban/ban12345_02.jpg');
    expect($urls)->not->toContain('https://www.hlj.com/media/wysiwyg/shipping/fedex.png');
    expect($urls)->not->toContain('https://www.hlj.com/productimages/shipping/dhl.jpg');
    expect($urls)->not->toContain('https://www.hlj.com/productimages/ban/fedex.jpg');
    expect($urls)->not->toContain('https://www.hlj.com/productimages/ban/paypal.png');
});

it('restricts /productimages/ URLs to the expected PDP product code when provided (even if other productimages exist)', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <body>
    <div class="fotorama" data-nav="thumbs">
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/bans60760_0.jpg" />
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/bans60760_1.jpg" />
      <!-- Non-product, but still under /productimages/ and with a generic filename that doesn't match blocked tokens -->
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ui/iconset_01.png" />
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ui/iconset_02.png" />
    </div>
  </body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $urls = $parser->extractImageUrls($html, 'bans60760');

    expect($urls)->toContain('https://www.hlj.com/productimages/ban/bans60760_0.jpg');
    expect($urls)->toContain('https://www.hlj.com/productimages/ban/bans60760_1.jpg');
    expect($urls)->not->toContain('https://www.hlj.com/productimages/ui/iconset_01.png');
    expect($urls)->not->toContain('https://www.hlj.com/productimages/ui/iconset_02.png');
});

it('can extract the HLJ product code suffix from the PDP URL', function (): void {
    $parser = new HljHtmlParser();

    expect($parser->productCodeFromPdpUrl('https://www.hlj.com/1-100-scale-mg-wing-gundam-zero-ew-ver-ka-bans60760'))->toBe('bans60760');
    expect($parser->productCodeFromPdpUrl('https://www.hlj.com/1-144-scale-hg-gundam-gp01fb-banh603920-up'))->toBe('banh603920-up');
    expect($parser->productCodeFromPdpUrl('https://www.hlj.com/1-100-scale-mg-zz-gundam-ver-ka-w-premium-decal-bann22236'))->toBe('bann22236');
    expect($parser->productCodeFromPdpUrl('https://www.hlj.com/30mm-1-144-optional-parts-set-12-hand-parts-multi-joint-hbj60934'))->toBe('hbj60934');
});

it('normalizes HLJ product image URLs by stripping versioning query params', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <body>
    <div class="fotorama" data-nav="thumbs">
      <img class="fotorama__img" src="https://www.hlj.com/productimages/hbj/hbj60934_0.jpg?v=123" />
      <img class="fotorama__img" src="https://www.hlj.com/media/catalog/product/a/b/ab123.jpg?foo=bar#frag" />
    </div>
  </body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $urls = $parser->extractImageUrls($html);

    expect($urls)->toContain('https://www.hlj.com/productimages/hbj/hbj60934_0.jpg');
    expect($urls)->toContain('https://www.hlj.com/media/catalog/product/a/b/ab123.jpg');
    expect($urls)->not->toContain('https://www.hlj.com/productimages/hbj/hbj60934_0.jpg?v=123');
    expect($urls)->not->toContain('https://www.hlj.com/media/catalog/product/a/b/ab123.jpg?foo=bar#frag');
});

it('extracts product image URLs embedded in scripts/attrs while excluding shipping/payment images', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <head></head>
  <body>
    <script>
      window.__HLJ = {
        images: [
          "/productimages/ban/ban978539_0.jpg",
          "/productimages/ban/ban978539_1.jpg",
          "https://www.hlj.com/productimages/ban/ban978539_2.jpg",
          "/productimages/shipping/dhl.jpg",
          "/media/catalog/product/a/b/ab123_03.jpg"
        ]
      };
    </script>
  </body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $urls = $parser->extractImageUrls($html);

    expect($urls)->toContain('https://www.hlj.com/productimages/ban/ban978539_0.jpg');
    expect($urls)->toContain('https://www.hlj.com/productimages/ban/ban978539_1.jpg');
    expect($urls)->toContain('https://www.hlj.com/productimages/ban/ban978539_2.jpg');
    expect($urls)->toContain('https://www.hlj.com/media/catalog/product/a/b/ab123_03.jpg');

    // Exclude non-product buckets.
    expect($urls)->not->toContain('https://www.hlj.com/productimages/shipping/dhl.jpg');
});

it('extracts HLJ PDP URLs from search HTML including "-up" suffix', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <body>
    <a href="/1-144-scale-hg-gundam-gp01fb-banh603920-up">HG Gundam GP01Fb</a>
    <a href="/1-144-scale-hguc-some-other-kit-ban12345">Other</a>
  </body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $url = $parser->extractPdpUrlFromSearchHtml($html);

    expect($url)->toBe('https://www.hlj.com/1-144-scale-hg-gundam-gp01fb-banh603920-up');
});

it('selects the best fotorama when multiple carousels exist (avoid payment/shipping logos)', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <head></head>
  <body>
    <!-- A non-product carousel -->
    <div class="fotorama" data-nav="thumbs">
      <img src="https://www.hlj.com/media/wysiwyg/shipping/dhl.png" />
      <img src="https://www.hlj.com/media/wysiwyg/payment/paypal.png" />
      <img src="https://www.hlj.com/media/wysiwyg/payment/mastercard.png" />
    </div>

    <!-- The actual product image carousel -->
    <div class="fotorama" data-nav="thumbs">
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/ban99999_01.jpg" />
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/ban99999_02.jpg" />
      <img class="fotorama__img" src="https://www.hlj.com/productimages/ban/ban99999_03.jpg" />
    </div>
  </body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $urls = $parser->extractImageUrls($html);

    expect($urls)->toContain('https://www.hlj.com/productimages/ban/ban99999_01.jpg');
    expect($urls)->toContain('https://www.hlj.com/productimages/ban/ban99999_02.jpg');
    expect($urls)->toContain('https://www.hlj.com/productimages/ban/ban99999_03.jpg');
    expect($urls)->not->toContain('https://www.hlj.com/media/wysiwyg/shipping/dhl.png');
    expect($urls)->not->toContain('https://www.hlj.com/media/wysiwyg/payment/paypal.png');
    expect($urls)->not->toContain('https://www.hlj.com/media/wysiwyg/payment/mastercard.png');
});

it('extracts HLJ JAN code from JSON-LD GTIN fields when not present as "JAN Code:" text', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/ld+json">
      {
        "@context": "https://schema.org",
        "@type": "Product",
        "name": "Test Product",
        "gtin13": "4573102554543"
      }
    </script>
  </head>
  <body></body>
</html>
HTML;

    $parser = new HljHtmlParser();
    expect($parser->extractJanCodeFromPdpHtml($html))->toBe('4573102554543');
});

