<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = getenv('THEME_ID') ?: '190542250065';
if ($themeId === '190542250065' && getenv('SHOPIFY_LIVE_THEME_APPROVED') !== '1') {
    fwrite(STDERR, "Refusing live Rise theme push (190542250065). Set SHOPIFY_LIVE_THEME_APPROVED=1.\n");
    exit(1);
}

$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing — set THEME_ROOT to ovs-shopify-theme\n");
    exit(1);
}

$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$themeGid = 'gid://shopify/OnlineStoreTheme/'.$themeId;

$directFiles = [
    'snippets/ovs-store-preorder-attrs.liquid',
    'snippets/ovs-store-preorder-close-status-group.liquid',
    'assets/ovs-store-preorder-collection-filters.js',
    'sections/ovs-store-preorder-index.liquid',
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
if ($grid === '') {
    fwrite(STDERR, "Live grid missing\n");
    exit(1);
}

$grid = preg_replace(
    '/\r\n?/',
    "\n",
    $grid,
) ?? $grid;
$grid = preg_replace(
    '/if ovs_is_preorder_catalog\s*\n\s*assign ovs_products_per_page = 250\s*\n\s*elsif /',
    'if ',
    $grid,
    1,
) ?? $grid;
$grid = preg_replace(
    '/assign ovs_preorder_use_close_sort = true\s*\n\s*if collection\\.sort_by[\s\S]*?endif\s*\n/',
    "assign ovs_preorder_use_close_sort = false\n",
    $grid,
    1,
) ?? $grid;

if (! str_contains($grid, 'data-ovs-po-page-size')) {
    $grid = preg_replace(
        '/(id="product-grid"\n              data-id="\{\{ section\.id \}\}")/',
        "$1\n              {% if ovs_is_preorder_catalog %}\n                data-ovs-po-page-size=\"{{ ovs_products_per_page }}\"\n                data-ovs-po-section-id=\"{{ section.id }}\"\n              {% endif %}",
        $grid,
        1,
    ) ?? $grid;
}

if (! str_contains($grid, 'id="ovs-store-preorder-index"')) {
    $indexBlock = "  {%- if ovs_is_preorder_catalog -%}\n"
        ."    {%- paginate collection.products by 250 -%}\n"
        ."      <div id=\"ovs-store-preorder-index\" hidden>\n"
        ."        {%- for product in collection.products -%}\n"
        ."          <span\n"
        ."            {% render 'ovs-store-preorder-attrs', product: product %}\n"
        ."            data-ovs-po-position=\"{{ forloop.index0 }}\"\n"
        ."          ></span>\n"
        ."        {%- endfor -%}\n"
        ."      </div>\n"
        ."    {%- endpaginate -%}\n"
        ."  {%- endif -%}\n";
    if (str_contains($grid, '{%- if ovs_is_ts_catalog -%}')) {
        $grid = str_replace('{%- if ovs_is_ts_catalog -%}', $indexBlock.'  {%- if ovs_is_ts_catalog -%}', $grid);
    } else {
        $grid = preg_replace(
            '/(\{%- paginate (?:ovs_mk_product_collection|collection)\.products by ovs_products_per_page -%\})/',
            $indexBlock.'$1',
            $grid,
            1,
        ) ?? $grid;
    }
}

if (! str_contains($grid, 'id="ovs-po-filter-pagination"')) {
    if (str_contains($grid, 'id="ovs-mk-filter-pagination"')) {
        $grid = str_replace(
            '{%- endif -%}'."\n\n".'            {%- if paginate.pages > 1 -%}',
            '{%- endif -%}'."\n".'            {%- if ovs_is_preorder_catalog -%}'."\n".'              <div id="ovs-po-filter-pagination" class="pagination-wrapper ovs-po-filter-pagination page-width" hidden></div>'."\n".'            {%- endif -%}'."\n\n".'            {%- if paginate.pages > 1 -%}',
            $grid,
        );
    } else {
        $grid = preg_replace(
            '/(\{%- if paginate\.pages > 1 -%\})/',
            "{%- if ovs_is_preorder_catalog -%}\n              <div id=\"ovs-po-filter-pagination\" class=\"pagination-wrapper ovs-po-filter-pagination page-width\" hidden></div>\n            {%- endif -%}\n            $1",
            $grid,
            1,
        ) ?? $grid;
    }
}

$inputs[] = ['filename' => 'sections/main-collection-product-grid.liquid', 'body' => ['type' => 'TEXT', 'value' => $grid]];

if (preg_match('/if ovs_is_preorder_catalog\s*\n\s*assign ovs_products_per_page = 250/', $grid)) {
    fwrite(STDERR, "Grid still forces 250 products for pre-orders\n");
    exit(1);
}

$result = $client->query($mutation, [
    'themeId' => $themeGid,
    'files' => $inputs,
]);
echo json_encode($result['data']['themeFilesUpsert'] ?? $result, JSON_PRETTY_PRINT).PHP_EOL;
$errors = $result['data']['themeFilesUpsert']['userErrors'] ?? [];
exit($errors !== [] ? 1 : 0);
