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
    'snippets/ovs-store-preorder-pdp.liquid',
    'snippets/ovs-store-preorder-pricing.liquid',
    'snippets/ovs-store-preorder-closed.liquid',
    'snippets/ovs-store-preorder-line-properties.liquid',
    'assets/ovs-store-preorder-isolate.css',
    'assets/ovs-store-preorder-isolate.js',
    'assets/ovs-layout.css',
    'assets/component-cart-drawer.css',
];

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

$response = $client->query($query, [
    'themeId' => $themeGid,
    'filenames' => [
        'layout/theme.liquid',
        'snippets/card-product.liquid',
        'snippets/buy-buttons.liquid',
        'sections/main-product.liquid',
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

$themeLiquid = $byName['layout/theme.liquid'] ?? '';
if ($themeLiquid !== '' && ! str_contains($themeLiquid, 'ovs-store-preorder-isolate.css')) {
    $inject = "  {{ 'ovs-store-preorder-isolate.css' | asset_url | stylesheet_tag }}\n"
        ."  <script src=\"{{ 'ovs-store-preorder-isolate.js' | asset_url }}\" defer></script>\n";
    $themeLiquid = preg_replace('/<\/head>/', $inject.'</head>', $themeLiquid, 1) ?? $themeLiquid;
    $inputs[] = ['filename' => 'layout/theme.liquid', 'body' => ['type' => 'TEXT', 'value' => $themeLiquid]];
}

$card = $byName['snippets/card-product.liquid'] ?? '';
if ($card !== '') {
    $cardChanged = false;
    if (! str_contains($card, 'ovs-store-preorder-line-properties')) {
        $card = preg_replace(
            '/(<input[^>]*class="product-variant-id"[^>]*>)/',
            "$1\n                  {% render 'ovs-store-preorder-line-properties', product: card_product %}",
            $card,
            1,
        ) ?? $card;
        $cardChanged = str_contains($card, 'ovs-store-preorder-line-properties');
    }
    if (str_contains($card, '{{ card_product.title | escape }}')) {
        $card = str_replace(
            '{{ card_product.title | escape }}',
            "{{ card_product.title | remove_last: ' (PO)' | escape }}",
            $card,
        );
        $cardChanged = true;
    }
    if (! str_contains($card, 'ovs-store-preorder-pricing')) {
        $card = preg_replace(
            "/(\\{%-?\\s*render\\s+'price'[^%]*%\\})/",
            "$1\n              {% render 'ovs-store-preorder-pricing', product: card_product %}",
            $card,
            1,
        ) ?? $card;
        $cardChanged = true;
    }
    if (str_contains($card, 'Pre-order — pay deposit')) {
        $card = str_replace(
            "{% if card_product.tags contains 'sp:store-preorder' %}Pre-order — pay deposit{% else %}{{ 'products.product.add_to_cart' | t }}{% endif %}",
            "{{ 'products.product.add_to_cart' | t }}",
            $card,
        );
        $cardChanged = true;
    }
    if (str_contains($card, 'Preorder closed') && ! str_contains($card, "Pre-order\n") && ! str_contains($card, 'Pre-order')) {
        $card = str_replace(
            "{%- if ovs_preorder_closed == '1' -%}\n                        Preorder closed\n                      {%- elsif card_product.selected_or_first_available_variant.available -%}\n                        {{ 'products.product.add_to_cart' | t }}",
            "{%- if ovs_preorder_closed == '1' -%}\n                        Preorder closed\n                      {%- elsif card_product.tags contains 'sp:store-preorder' or card_product.type == 'Pre-order' -%}\n                        Pre-order\n                      {%- elsif card_product.selected_or_first_available_variant.available -%}\n                        {{ 'products.product.add_to_cart' | t }}",
            $card,
        );
        $cardChanged = str_contains($card, 'Pre-order');
    }
    if (! str_contains($card, 'Preorder closed')) {
        $card = str_replace(
            "{% if card_product.selected_or_first_available_variant.available == false %}\n                      disabled\n                    {% endif %}",
            "{% capture ovs_preorder_closed %}{% render 'ovs-store-preorder-closed', product: card_product %}{% endcapture %}\n                    {% if card_product.selected_or_first_available_variant.available == false or ovs_preorder_closed == '1' %}\n                      disabled\n                    {% endif %}",
            $card,
        );
        $card = str_replace(
            "{%- if card_product.selected_or_first_available_variant.available -%}\n                        {{ 'products.product.add_to_cart' | t }}\n                      {%- else -%}\n                        {{ 'products.product.sold_out' | t }}\n                      {%- endif -%}",
            "{%- if ovs_preorder_closed == '1' -%}\n                        Preorder closed\n                      {%- elsif card_product.selected_or_first_available_variant.available -%}\n                        {{ 'products.product.add_to_cart' | t }}\n                      {%- else -%}\n                        {{ 'products.product.sold_out' | t }}\n                      {%- endif -%}",
            $card,
        );
        if (str_contains($card, 'Preorder closed')) {
            $cardChanged = true;
        }
    }
    if ($cardChanged) {
        $inputs[] = ['filename' => 'snippets/card-product.liquid', 'body' => ['type' => 'TEXT', 'value' => $card]];
    }
}

$buy = $byName['snippets/buy-buttons.liquid'] ?? '';
if ($buy !== '') {
    $buyChanged = false;
    if (! str_contains($buy, 'ovs-store-preorder-line-properties')) {
        $buy = preg_replace(
            '/(<input[^>]*class="product-variant-id"[^>]*>)/',
            "$1\n        {% render 'ovs-store-preorder-line-properties', product: product %}",
            $buy,
            1,
        ) ?? $buy;
        $buyChanged = str_contains($buy, 'ovs-store-preorder-line-properties');
    }
    if (str_contains($buy, 'Pre-order — pay deposit')) {
        $buy = str_replace(
            "{% if product.tags contains 'sp:store-preorder' %}Pre-order — pay deposit{% else %}{{ 'products.product.add_to_cart' | t }}{% endif %}",
            "{{ 'products.product.add_to_cart' | t }}",
            $buy,
        );
        $buyChanged = true;
    }
    if (str_contains($buy, 'Preorder closed') && ! str_contains($buy, 'Pre-order')) {
        $buy = str_replace(
            "{%- if ovs_preorder_closed == '1' -%}\n                Preorder closed\n              {%- elsif product.selected_or_first_available_variant == null -%}",
            "{%- if ovs_preorder_closed == '1' -%}\n                Preorder closed\n              {%- elsif product.tags contains 'sp:store-preorder' or product.type == 'Pre-order' -%}\n                Pre-order\n              {%- elsif product.selected_or_first_available_variant == null -%}",
            $buy,
        );
        $buy = str_replace(
            "{% if ovs_preorder_closed == '1' %}Preorder closed{% else %}{{ 'products.product.add_to_cart' | t }}{% endif %}",
            "{% if ovs_preorder_closed == '1' %}Preorder closed{% elsif product.tags contains 'sp:store-preorder' or product.type == 'Pre-order' %}Pre-order{% else %}{{ 'products.product.add_to_cart' | t }}{% endif %}",
            $buy,
        );
        $buyChanged = str_contains($buy, 'Pre-order');
    }
    if (! str_contains($buy, 'Preorder closed')) {
        $buy = str_replace(
            "{{ 'products.product.add_to_cart' | t }}",
            "{% capture ovs_preorder_closed %}{% render 'ovs-store-preorder-closed', product: product %}{% endcapture %}{% if ovs_preorder_closed == '1' %}Preorder closed{% else %}{{ 'products.product.add_to_cart' | t }}{% endif %}",
            $buy,
        );
        $disabledNeedle = "{% if product.selected_or_first_available_variant.available == false\n            or quantity_rule_soldout\n            or product.selected_or_first_available_variant == null\n          %}";
        $disabledReplace = "{% if product.selected_or_first_available_variant.available == false\n            or quantity_rule_soldout\n            or product.selected_or_first_available_variant == null\n            or ovs_preorder_closed == '1'\n          %}";
        if (str_contains($buy, $disabledNeedle)) {
            $buy = str_replace($disabledNeedle, $disabledReplace, $buy);
        }
        $buyChanged = str_contains($buy, 'Preorder closed');
    }
    if ($buyChanged) {
        $inputs[] = ['filename' => 'snippets/buy-buttons.liquid', 'body' => ['type' => 'TEXT', 'value' => $buy]];
    }
}

$pdp = $byName['sections/main-product.liquid'] ?? '';
if ($pdp !== '') {
    if (str_contains($pdp, '{{ product.title | escape }}')) {
        $pdp = str_replace(
            '{{ product.title | escape }}',
            "{{ product.title | remove_last: ' (PO)' | escape }}",
            $pdp,
        );
    }
    $pdp = preg_replace('/\s*\{%\s*render\s+\'ovs-store-preorder-pdp\'\s*%\}/', '', $pdp) ?? $pdp;
    if (preg_match('/(<div id="price-\{\{\s*section\.id\s*\}}"[^>]*>)/', $pdp) === 1) {
        $pdp = preg_replace(
            '/(<div id="price-\{\{\s*section\.id\s*\}}"[^>]*>[\s\S]*?<\/div>)/',
            "$1\n                {% render 'ovs-store-preorder-pdp' %}",
            $pdp,
            1,
        ) ?? $pdp;
        $inputs[] = ['filename' => 'sections/main-product.liquid', 'body' => ['type' => 'TEXT', 'value' => $pdp]];
    }
}

$result = $client->query($mutation, [
    'themeId' => $themeGid,
    'files' => $inputs,
]);
echo json_encode($result['data']['themeFilesUpsert'] ?? $result, JSON_PRETTY_PRINT).PHP_EOL;
$errors = $result['data']['themeFilesUpsert']['userErrors'] ?? [];
exit($errors !== [] ? 1 : 0);
