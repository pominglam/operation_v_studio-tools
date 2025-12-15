<?php

declare(strict_types=1);

use App\Services\PriceResearch\Support\HtmlPriceParser;

it('prefers CAD-labelled prices over unrelated dollar amounts', function (): void {
    $html = <<<'HTML'
<div>FREE SHIPPING on orders $80 or more</div>
<div class="price">$21.99 CAD</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(21.99);
    expect($out['original_price'])->toBeNull();
});

it('only infers sale prices when the page indicates a compare-at/list price', function (): void {
    $html = <<<'HTML'
<div class="price">
  <span class="current">$18.00 CAD</span>
  <span class="line-through">$20.00 CAD</span>
</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(18.0);
    expect($out['original_price'])->toBe(20.0);
});

it('does not mistake free-shipping thresholds for the product price', function (): void {
    $html = <<<'HTML'
<div class="banner">FREE SHIPPING on orders $80 or more within Canada</div>
<div class="product">
  <span class="price">$16.99</span>
  <button>Add to cart</button>
</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(16.99);
    expect($out['availability'])->toBe('in_stock');
});

it('prefers the PDP CAD price over smaller CAD amounts in shipping context', function (): void {
    $html = <<<'HTML'
<div class="banner">Shipping: $2.00 CAD</div>
<h1>Bandai Hobby HGCE 1/144 #13 Gundam Astray Blue Frame</h1>
<div class="product__price">$21.99 CAD</div>
<button>SOLD OUT</button>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(21.99);
    expect($out['availability'])->toBe('sold_out');
});

it('extracts original price when a compare-at price is present (Shopify-style)', function (): void {
    $html = <<<'HTML'
<div class="price">
  <span class="badge">Save 20%</span>
  <span class="price-item price-item--regular">$19.99 CAD</span>
  <span class="price-item price-item--sale">$15.99 CAD</span>
</div>
<button>Add to cart</button>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(15.99);
    expect($out['original_price'])->toBe(19.99);
    expect($out['availability'])->toBe('in_stock');
});

it('does not drop the PDP price when "Shipping Policy" is nearby (Hobby Bee)', function (): void {
    $html = <<<'HTML'
<div class="header">HOBBY BEE $14.99 SHIPPING CANADA &amp; US</div>
<p class="product-single__prices">
  <span class="visually-hidden">Regular price</span>
  <span id="ProductPrice" class="product-single__price" itemprop="price" content="21.99">
    <span class="money">$ 21.99 CAD</span>
  </span>
  <a href="/shipping-policy">Shipping Policy</a>
</p>
<div class="btn">SOLD OUT</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(21.99);
    expect($out['availability'])->toBe('sold_out');
});

it('extracts original and current price from Panda compare-at/current markup', function (): void {
    $html = <<<'HTML'
<div class="price__compare-at--hidden" data-compare-price-hidden>
  <span class="visually-hidden">Original price</span>
  <span class="money price__compare-at--single" data-price-compare>
    <span class="money">$19.99 CAD</span>
  </span>
</div>
<div class="price__current price__current--on-sale" data-price-container>
  <span class="visually-hidden">Current price</span>
  <span class="money" data-price>$15.99 CAD</span>
</div>
<button>Add to cart</button>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(15.99);
    expect($out['original_price'])->toBe(19.99);
    expect($out['availability'])->toBe('in_stock');
});

it('merges Panda compare-at/original from markup even when JSON-LD only includes current price', function (): void {
    $html = <<<'HTML'
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","offers":{"@type":"Offer","priceCurrency":"CAD","price":"15.99","availability":"https://schema.org/InStock"}}
</script>
<div class="product-main">
  <div class="price__compare-at--hidden" data-compare-price-hidden>
    <span class="money price__compare-at--single" data-price-compare><span class="money">$19.99 CAD</span></span>
  </div>
  <div class="price__current price__current--on-sale" data-price-container>
    <span class="money" data-price>$15.99 CAD</span>
  </div>
</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(15.99);
    expect($out['original_price'])->toBe(19.99);
    expect($out['availability'])->toBe('in_stock');
});

it('does not override an existing price when inferring original price (avoids picking unrelated high prices)', function (): void {
    $html = <<<'HTML'
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","offers":{"@type":"Offer","priceCurrency":"CAD","price":"15.99","availability":"https://schema.org/InStock"}}
</script>
<div class="price__current  price__current--on-sale"><span class=money>$15.99 CAD</span></div>
<div class="price__compare-at visible"><span class=money>$19.99 CAD</span></div>
<div class="related-products">
  <span class=money>$999.99 CAD</span>
</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(15.99);
    expect($out['original_price'])->toBe(19.99);
});

it('extracts CanadianGundam PrestaShop PDP links from search results', function (): void {
    $html = <<<'HTML'
<ul id="product_list">
  <li>
    <a class="product_img_link" href="https://www.canadiangundam.com/1144-scale/1447-hg-gundam-astray-blue-frame-13.html?search_query=HG+1%2F144+%2313+Gundam+Astray+Blue+Frame&amp;results=3679">HG Gundam Astray Blue Frame (13)</a>
  </li>
</ul>
HTML;

    $parser = new HtmlPriceParser;
    $urls = $parser->extractCandidateProductUrls($html, 'https://www.canadiangundam.com');

    expect($urls)->toContain('https://www.canadiangundam.com/1144-scale/1447-hg-gundam-astray-blue-frame-13.html?search_query=HG+1%2F144+%2313+Gundam+Astray+Blue+Frame&results=3679');
});

it('extracts HobbyWholesale product PDP links and excludes category .html pages', function (): void {
    $html = <<<'HTML'
<nav>
  <a href="https://hobbywholesale.com/radio-control.html">Radio Control</a>
  <a href="https://hobbywholesale.com/models/plastic-models.html">Plastic Models</a>
  <a class="product-item-link" href="https://hobbywholesale.com/models/plastic-models/gundam-models/hg/hg-1-144-13-gundam-astray-blue-frame-ban5060358.html">HG 1/144 #13 Gundam Astray Blue Frame</a>
</nav>
HTML;

    $parser = new HtmlPriceParser;
    $urls = $parser->extractCandidateProductUrls($html, 'https://hobbywholesale.com');

    expect($urls)->toContain('https://hobbywholesale.com/models/plastic-models/gundam-models/hg/hg-1-144-13-gundam-astray-blue-frame-ban5060358.html');
    expect($urls)->not->toContain('https://hobbywholesale.com/radio-control.html');
    expect($urls)->not->toContain('https://hobbywholesale.com/models/plastic-models.html');
});

it('extracts Meeplemart PDP links and excludes /store/ category/search pages', function (): void {
    $html = <<<'HTML'
<nav>
  <a href="/store/c/1146-GREAT-DEALS-1146.aspx">Great Deals</a>
  <a href="/store/Search.aspx?SearchTerms=Gundam">Search</a>
  <a href="/model-kits-8338.aspx">Category</a>
  <a href="/gundam-seed-msv-series-hg-1/144-13-gundam-astray-blue-frame.aspx">PDP</a>
</nav>
HTML;

    $parser = new HtmlPriceParser;
    $urls = $parser->extractCandidateProductUrls($html, 'https://www.meeplemart.com');

    expect($urls)->toContain('https://www.meeplemart.com/gundam-seed-msv-series-hg-1/144-13-gundam-astray-blue-frame.aspx');
    expect($urls)->not->toContain('https://www.meeplemart.com/store/c/1146-GREAT-DEALS-1146.aspx');
    expect($urls)->not->toContain('https://www.meeplemart.com/store/Search.aspx?SearchTerms=Gundam');
    expect($urls)->not->toContain('https://www.meeplemart.com/model-kits-8338.aspx');
});

it('extracts HobbyWholesale (Magento) price + stock from product-info-main and ignores shipping thresholds', function (): void {
    $html = <<<'HTML'
<div class="banner">FREE SHIPPING on orders $80 or more within Canada</div>
<div class="product-info-main">
  <div class="page-title-wrapper product">
    <h1 class="page-title"><span class="base">HG 1/144 #13 Gundam Astray Blue Frame</span></h1>
  </div>
  <div class="product-info-price">
    <div class="price-box price-final_price">
      <span id="product-price-53250" data-price-amount="16.99" data-price-type="finalPrice" class="price-wrapper">
        <span class="price">$16.99</span>
      </span>
    </div>
  </div>
  <div class="product-info-stock-sku">
    <div class="stock unavailable" title="Out of stock"><span class="label"> Out of stock</span></div>
  </div>
</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(16.99);
    expect($out['availability'])->toBe('sold_out');
    expect($out['original_price'])->toBeNull();
});

it('extracts PrestaShop microdata price + availability from our_price_display', function (): void {
    $html = <<<'HTML'
<p class="our_price_display" itemprop="offers" itemscope="" itemtype="https://schema.org/Offer">
  <link itemprop="availability" href="https://schema.org/InStock">
  <span id="our_price_display" class="price" itemprop="price" content="23.29">$23.29</span>
  <meta itemprop="priceCurrency" content="CAD">
</p>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(23.29);
    expect($out['availability'])->toBe('in_stock');
    expect($out['original_price'])->toBeNull();
});

it('extracts Hobby Bee price and availability from product-single__meta and ignores shipping/FAQ amounts', function (): void {
    $html = <<<'HTML'
<div class="header">HOBBY BEE $14.99 SHIPPING CANADA &amp; US</div>
<div class="product-single__meta small--text-center">
  <h1 class="product-single__title" itemprop="name">Bandai Hobby HGCE 1/144 #13 Gundam Astray Blue Frame (5060358)</h1>
  <div itemprop="offers" itemscope itemtype="http://schema.org/Offer">
    <meta itemprop="priceCurrency" content="CAD">
    <link itemprop="availability" href="http://schema.org/OutOfStock">
    <p class="product-single__prices">
      <span id="ProductPrice" class="product-single__price" itemprop="price" content="21.99"><span class="money">$ 21.99 CAD</span></span>
    </p>
    <button id="AddToCart" disabled><span>Sold Out</span></button>
  </div>
</div>
<div class="faq">
  <p>Shipping insurance can cover items valued at up to $400 CAD.</p>
</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(21.99);
    expect($out['availability'])->toBe('sold_out');
    expect($out['original_price'])->toBeNull();
});

it('does not infer an original price from unrelated prices when JSON-LD provides the current price (Shopify)', function (): void {
    $html = <<<'HTML'
<html>
  <head>
    <script type="application/ld+json">
      {
        "@context":"http://schema.org/",
        "@type":"Product",
        "name":"1/144 HG #13 Gundam Astray Blue Frame",
        "sku":"5060358",
        "offers":{
          "@type":"Offer",
          "priceCurrency":"CAD",
          "price":18.99,
          "availability":"http://schema.org/OutOfStock"
        }
      }
    </script>
  </head>
  <body>
    <!-- PDP product form scope (what we want to parse) -->
    <div data-product-form>
      <form>
        <span class="money">$18.99</span>
      </form>
    </div>

    <!-- Unrelated recommended product card prices elsewhere on the page -->
    <div class="price-item--sale">Sale</div>
    <div class="product-thumbnail"><span class="money"><span class="money">$19.99</span></span></div>
  </body>
</html>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(18.99);
    expect($out['availability'])->toBe('sold_out');
    expect($out['original_price'])->toBeNull();
});

it('extracts Panda online shipping availability from the PDP widget', function (): void {
    $html = <<<'HTML'
<div class="product-main">
  <span class="money price__compare-at--single" data-price-compare="">$19.99 CAD</span>
  <div class="price__current  price__current--on-sale" data-price-container="">
    <span class="visually-hidden">Current price</span>
    <span class="money" data-price="">$15.99 CAD</span>
  </div>
  <div class="iia-title-text"><span class="iia-name">Online Shipping</span> - <span class="iia-stock-threshold" style="color:#7ed321;">In stock</span></div>
</div>
HTML;

    $parser = new HtmlPriceParser;
    $out = $parser->extractPriceAndAvailabilityFromHtml($html);

    expect($out['price'])->toBe(15.99);
    expect($out['original_price'])->toBe(19.99);
    expect($out['availability'])->toBe('in_stock');
});
