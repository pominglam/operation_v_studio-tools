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

$newFiles = [
    'assets/ovs-collection-arrival-sort.js',
    'snippets/ovs-collection-arrival-date.liquid',
];

$patchFiles = [
    'sections/main-collection-product-grid.liquid',
    'assets/facets.js',
];

$query = <<<'GQL'
query ThemeFiles($themeId: ID!, $filenames: [String!]) {
  theme(id: $themeId) {
    files(filenames: $filenames, first: 20) {
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

function patchMainCollectionGrid(string $content): string
{
    if (! str_contains($content, 'ovs-collection-arrival-sort.js')) {
        $content = str_replace(
            "{{ 'component-price.css' | asset_url | stylesheet_tag }}\n",
            "{{ 'component-price.css' | asset_url | stylesheet_tag }}\n<script src=\"{{ 'ovs-collection-arrival-sort.js' | asset_url }}\" defer=\"defer\"></script>\n",
            $content,
        );
    }

    if (str_contains($content, 'data-ovs-arrived-at') || str_contains($content, 'ovs-collection-arrival-date')) {
        return $content;
    }

    $simpleLoop = <<<'LIQUID'
              {%- for product in collection.products -%}
                {% assign lazy_load = false %}
                {%- if forloop.index > 2 -%}
                  {%- assign lazy_load = true -%}
                {%- endif -%}
                <li
                  class="grid__item{% if settings.animations_reveal_on_scroll %} scroll-trigger animate--slide-in{% endif %}"
                  {% if settings.animations_reveal_on_scroll %}
                    data-cascade
                    style="--animation-order: {{ forloop.index }};"
                  {% endif %}
                >
                  {% render 'card-product',
LIQUID;

    $sortedLoop = <<<'LIQUID'
              {%- capture ovs_arrival_sort_data -%}
                {%- for product in collection.products -%}
                  {%- assign ovs_arrived_at = product.metafields.ovs_catalog.latest_po_received_date.value | default: '' -%}
                  {%- assign ovs_arrival_sort_key = ovs_arrived_at | append: '~~~' | append: forloop.index0 -%}
                  {{- ovs_arrival_sort_key -}},
                {%- endfor -%}
              {%- endcapture -%}
              {%- assign ovs_arrival_sort_keys = ovs_arrival_sort_data | split: ',' | sort | reverse -%}
              {%- for ovs_sort_key in ovs_arrival_sort_keys -%}
                {%- if ovs_sort_key == blank -%}
                  {%- continue -%}
                {%- endif -%}
                {%- assign ovs_product_index = ovs_sort_key | split: '~~~' | last | plus: 0 -%}
                {%- assign product = collection.products[ovs_product_index] -%}
                {%- if product == blank -%}
                  {%- continue -%}
                {%- endif -%}
                {% assign lazy_load = false %}
                {%- if forloop.index > 2 -%}
                  {%- assign lazy_load = true -%}
                {%- endif -%}
                <li
                  class="grid__item{% if settings.animations_reveal_on_scroll %} scroll-trigger animate--slide-in{% endif %}"
                  {% render 'ovs-collection-arrival-date', product: product %}
                  {% if settings.animations_reveal_on_scroll %}
                    data-cascade
                    style="--animation-order: {{ forloop.index }};"
                  {% endif %}
                >
                  {% render 'card-product',
LIQUID;

    if (str_contains($content, $simpleLoop)) {
        return str_replace($simpleLoop, $sortedLoop, $content);
    }

    fwrite(STDERR, "Refusing: live main-collection product loop anchor not found\n");
    exit(1);
}

function patchFacetsJs(string $content): string
{
    if (str_contains($content, 'ovsSortCollectionByArrivalDate')) {
        return $content;
    }

    $needle = "      .querySelectorAll('.scroll-trigger')\n      .forEach((element) => {\n        element.classList.add('scroll-trigger--cancel');\n      });\n  }";
    $replacement = "      .querySelectorAll('.scroll-trigger')\n      .forEach((element) => {\n        element.classList.add('scroll-trigger--cancel');\n      });\n\n    if (typeof window.ovsSortCollectionByArrivalDate === 'function') {\n      window.ovsSortCollectionByArrivalDate();\n    }\n  }";

    if (! str_contains($content, $needle)) {
        fwrite(STDERR, "Refusing: live facets.js missing renderProductGridContainer anchor\n");
        exit(1);
    }

    return str_replace($needle, $replacement, $content);
}

$inputs = [];
foreach ($newFiles as $key) {
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

$fetchNames = $patchFiles;
$response = $client->query($query, [
    'themeId' => $themeGid,
    'filenames' => $fetchNames,
]);
$remoteByName = [];
foreach (($response['data']['theme']['files']['nodes'] ?? []) as $node) {
    $name = is_string($node['filename'] ?? null) ? $node['filename'] : '';
    $body = is_string($node['body']['content'] ?? null) ? $node['body']['content'] : '';
    if ($name !== '') {
        $remoteByName[$name] = $body;
    }
}

foreach ($patchFiles as $key) {
    $workspacePath = $themeRoot.'/'.$key;
    $content = $remoteByName[$key] ?? '';
    if ($content === '' && is_readable($workspacePath)) {
        $content = file_get_contents($workspacePath);
    }
    if ($content === '') {
        fwrite(STDERR, "Missing remote or workspace content for {$key}\n");
        exit(1);
    }

    if ($key === 'sections/main-collection-product-grid.liquid') {
        $content = patchMainCollectionGrid($content);
    }
    if ($key === 'assets/facets.js') {
        $content = patchFacetsJs($content);
    }

    $inputs[] = [
        'filename' => $key,
        'body' => ['type' => 'TEXT', 'value' => $content],
    ];
}

$upsert = $client->query($mutation, [
    'themeId' => $themeGid,
    'files' => $inputs,
]);

$errors = $upsert['data']['themeFilesUpsert']['userErrors'] ?? [];
if ($errors !== []) {
    fwrite(STDERR, json_encode($errors, JSON_PRETTY_PRINT)."\n");
    exit(1);
}

$upserted = array_map(
    static fn (array $row): string => (string) ($row['filename'] ?? ''),
    $upsert['data']['themeFilesUpsert']['upsertedThemeFiles'] ?? [],
);

echo 'Theme '.$themeId." arrival-sort push OK\n";
foreach ($upserted as $name) {
    echo '  - '.$name."\n";
}
