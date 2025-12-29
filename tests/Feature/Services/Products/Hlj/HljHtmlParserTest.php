<?php

declare(strict_types=1);

use App\Services\Products\Hlj\HljHtmlParser;

it('decodes double-encoded entities in JSON-LD description', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <script type="application/ld+json">
      {
        "@type": "Product",
        "name": "Test",
        "description": "Two years&amp;nbsp;&amp;nbsp;after it wasn&amp;#39;t enough"
      }
    </script>
  </head>
  <body></body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $out = $parser->extractTitleAndDescription($html);

    expect($out['title'])->toBe('Test');
    expect($out['description_html'])->toContain("wasn&#039;t");
    expect($out['description_html'])->not->toContain('&amp;#39;');
    expect($out['description_html'])->not->toContain('&amp;nbsp;');
});

it('prefers the PDP HTML description block over JSON-LD to preserve formatting', function (): void {
    $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <meta property="og:title" content="RG 1/144 GOD GUNDAM" />
    <script type="application/ld+json">
      {
        "@type": "Product",
        "name": "JSON LD Title",
        "description": "Plain text description"
      }
    </script>
  </head>
  <body>
    <div class="product-description">
      <h3>Description</h3>
      <p>Line 1</p>
      <ul>
        <li>Item A</li>
        <li>Item B</li>
      </ul>
    </div>
  </body>
</html>
HTML;

    $parser = new HljHtmlParser();
    $out = $parser->extractTitleAndDescription($html);

    expect($out['title'])->toBe('RG 1/144 GOD GUNDAM');
    expect($out['description_html'])->toContain('<p>Line 1</p>');
    expect($out['description_html'])->toContain('<ul>');
    expect($out['description_html'])->toContain('<li>Item A</li>');
    expect($out['description_html'])->not->toContain('Plain text description');
});


