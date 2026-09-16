<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = getenv('THEME_ID') ?: '190542250065';
$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing — set THEME_ROOT to ovs-shopify-theme\n");
    exit(1);
}

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$themeGid = 'gid://shopify/OnlineStoreTheme/'.$themeId;

$directFiles = [
    'snippets/ovs-store-preorder-attrs.liquid',
    'snippets/ovs-store-preorder-collection-filters.liquid',
    'assets/ovs-store-preorder-collection-filters.js',
    'assets/ovs-store-preorder-collection-filters.css',
    'assets/ovs-model-kit-collection-filters.css',
    'snippets/ovs-model-kit-filters-flat-group.liquid',
    'snippets/card-product.liquid',
    'snippets/ovs-tools-supplies-product-grid-item.liquid',
];

$inputs = [];
foreach ($directFiles as $key) {
    $path = $themeRoot.'/'.$key;
    if (! is_readable($path)) {
        fwrite(STDERR, "Missing: {$path}\n");
        exit(1);
    }
    $inputs[] = [
        'filename' => $key,
        'body' => ['type' => 'TEXT', 'value' => file_get_contents($path)],
    ];
}

$query = <<<'GQL'
query ThemeFiles($themeId: ID!, $filenames: [String!]) {
  theme(id: $themeId) {
    files(filenames: $filenames, first: 10) {
      nodes {
        filename
        body { ... on OnlineStoreThemeFileBodyText { content } }
      }
    }
  }
}
GQL;

$mutation = <<<'GQL'
mutation themeFilesUpsert($themeId: ID!, $files: [OnlineStoreThemeFilesUpsertFileInput!]!) {
  themeFilesUpsert(themeId: $themeId, files: $files) {
    upsertedThemeFiles { filename }
    userErrors { field message }
  }
}
GQL;

$response = $client->query($query, [
    'themeId' => $themeGid,
    'filenames' => [
        'sections/main-collection-product-grid.liquid',
        'snippets/facets.liquid',
    ],
]);
$byName = [];
foreach (($response['data']['theme']['files']['nodes'] ?? []) as $node) {
    $name = is_string($node['filename'] ?? null) ? $node['filename'] : '';
    $body = is_string($node['body']['content'] ?? null) ? $node['body']['content'] : '';
    if ($name !== '') {
        $byName[$name] = $body;
    }
}

$grid = $byName['sections/main-collection-product-grid.liquid'] ?? '';
if ($grid !== '') {
    if (! str_contains($grid, 'ovs-store-preorder-collection-filters.js')) {
        $assetBlock = "{%- if collection.handle == 'pre-orders' -%}\n"
            ."  {{ 'ovs-store-preorder-collection-filters.css' | asset_url | stylesheet_tag }}\n"
            ."  {{ 'ovs-model-kit-collection-filters.css' | asset_url | stylesheet_tag }}\n"
            ."  <script src=\"{{ 'ovs-store-preorder-collection-filters.js' | asset_url }}\" defer=\"defer\"></script>\n"
            ."{%- endif -%}\n";
        $grid = preg_replace('/(<div class="section-\{\{ section\.id \}\}-padding)/', $assetBlock.'$1', $grid, 1) ?? $grid;
    }
    if (! str_contains($grid, 'ovs_is_preorder_catalog')) {
        $pageSize = "    assign ovs_is_preorder_catalog = false\n"
            ."    if collection.handle == 'pre-orders'\n"
            ."      assign ovs_is_preorder_catalog = true\n"
            ."    endif\n"
            ."    if ovs_is_preorder_catalog\n"
            ."      assign ovs_products_per_page = 250\n"
            ."    endif\n";
        if (str_contains($grid, 'assign ovs_products_per_page = section.settings.products_per_page')) {
            $grid = str_replace(
                'assign ovs_products_per_page = section.settings.products_per_page',
                "assign ovs_products_per_page = section.settings.products_per_page\n".$pageSize,
                $grid,
            );
        } else {
            $grid = preg_replace(
                '/(\{%- paginate collection\.products by section\.settings\.products_per_page -%\})/',
                "{%- liquid\n  assign ovs_products_per_page = section.settings.products_per_page\n".$pageSize."-%}\n{%- paginate collection.products by ovs_products_per_page -%}",
                $grid,
                1,
            ) ?? $grid;
        }
    }
    if (! str_contains($grid, "ovs-store-preorder-collection-filters'")) {
        if (str_contains($grid, "render 'facets'")) {
            $grid = preg_replace(
                "/(\\{% render 'facets')/",
                "{% render 'ovs-store-preorder-collection-filters', collection: collection, section_id: section.id %}\n          $1",
                $grid,
                1,
            ) ?? $grid;
        }
    }
    $inputs[] = ['filename' => 'sections/main-collection-product-grid.liquid', 'body' => ['type' => 'TEXT', 'value' => $grid]];
}

$facets = $byName['snippets/facets.liquid'] ?? '';
if ($facets !== '') {
    if (! str_contains($facets, 'ovs_is_preorder_catalog')) {
        $flags = "  assign ovs_is_preorder_catalog = false\n"
            ."  if results.handle == 'pre-orders'\n"
            ."    assign ovs_is_preorder_catalog = true\n"
            ."    assign ovs_hide_price_filter = true\n"
            ."    assign ovs_hide_native_tag_filter = true\n"
            ."    assign ovs_hide_filter_heading = true\n"
            ."    assign ovs_hide_availability = true\n"
            ."  endif\n";
        $facets = preg_replace(
            '/(\{%- liquid\n  assign sort_by = results\.sort_by)/',
            "{%- liquid\n".$flags.'  assign sort_by = results.sort_by',
            $facets,
            1,
        ) ?? $facets;
        if (! str_contains($facets, 'ovs_is_preorder_catalog')) {
            $facets = preg_replace(
                '/(\{%- liquid\n  assign sort_by)/',
                "{%- liquid\n".$flags.'  assign sort_by',
                $facets,
                1,
            ) ?? $facets;
        }
    }
    if (! str_contains($facets, 'ovs_is_preorder_catalog')) {
        fwrite(STDERR, "Could not inject preorder flags into live facets.liquid\n");
        exit(1);
    }
    $skip = "                if ovs_is_preorder_catalog\n                  assign ovs_skip_filter = true\n                endif\n";
    if (! str_contains($facets, 'if ovs_is_preorder_catalog')) {
        $facets = preg_replace(
            '/(assign ovs_skip_filter = false\n)/',
            '$1'.$skip,
            $facets,
        ) ?? $facets;
    }
    if (! str_contains($facets, "ovs-store-preorder-collection-filters'")) {
        $renderDesktop = "            {% render 'ovs-store-preorder-collection-filters', collection: results, section_id: section.id %}\n";
        $renderMobile = "                {% render 'ovs-store-preorder-collection-filters', collection: results, section_id: section.id, filter_suffix: '-mobile' %}\n";
        $facets = preg_replace(
            '/(\{%- if enable_filtering -%\}\n            \{%- for filter in results\.filters -%\})/',
            "{%- if enable_filtering -%}\n".$renderDesktop.'            {%- for filter in results.filters -%}',
            $facets,
            1,
        ) ?? $facets;
        $facets = preg_replace(
            '/(\{%- if enable_filtering -%\}\n                \{%- for filter in results\.filters -%\})/',
            "{%- if enable_filtering -%}\n".$renderMobile.'                {%- for filter in results.filters -%}',
            $facets,
            1,
        ) ?? $facets;
    }
    $inputs[] = ['filename' => 'snippets/facets.liquid', 'body' => ['type' => 'TEXT', 'value' => $facets]];
}

$result = $client->query($mutation, [
    'themeId' => $themeGid,
    'files' => $inputs,
]);
echo json_encode($result['data']['themeFilesUpsert'] ?? $result, JSON_PRETTY_PRINT).PHP_EOL;
$errors = $result['data']['themeFilesUpsert']['userErrors'] ?? [];
exit($errors !== [] ? 1 : 0);
