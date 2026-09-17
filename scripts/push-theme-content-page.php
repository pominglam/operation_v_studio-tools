<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themeId = getenv('SHOPIFY_THEME_ID') ?: '190542250065';
if ($themeId === '190542250065' && getenv('SHOPIFY_LIVE_THEME_APPROVED') !== '1') {
    fwrite(STDERR, "Refusing live Rise theme push (190542250065). Set SHOPIFY_LIVE_THEME_APPROVED=1.\n");
    exit(1);
}

$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing\n");
    exit(1);
}

$textFiles = [
    'layout/theme.liquid',
    'assets/base.css',
    'sections/ovs-content-hero.liquid',
    'sections/ovs-content-page.liquid',
    'snippets/ovs-content-toc.liquid',
    'snippets/ovs-content-icon.liquid',
    'snippets/ovs-content-rubric.liquid',
    'snippets/ovs-content-dialog.liquid',
    'snippets/ovs-content-dialogs.liquid',
    'assets/ovs-content-page.css',
    'assets/ovs-content-page.js',
];
$templateFiles = [
    'templates/page.content.json',
];

$inputs = [];
foreach ($textFiles as $key) {
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

$heroPath = $themeRoot.'/assets/ovs-competition-2026-hero-wip-v4.jpg';
if (! is_readable($heroPath)) {
    fwrite(STDERR, "Missing: {$heroPath}\n");
    exit(1);
}
$inputs[] = [
    'filename' => 'assets/ovs-competition-2026-hero-wip-v4.jpg',
    'body' => ['type' => 'BASE64', 'value' => base64_encode((string) file_get_contents($heroPath))],
];

$mutation = <<<'GQL'
mutation themeFilesUpsert($themeId: ID!, $files: [OnlineStoreThemeFilesUpsertFileInput!]!) {
  themeFilesUpsert(themeId: $themeId, files: $files) {
    upsertedThemeFiles { filename }
    userErrors { field message }
  }
}
GQL;

/** @var ShopifyAdminGraphQlClientInterface $client */
$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$response = $client->query($mutation, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/'.$themeId,
    'files' => $inputs,
]);

echo json_encode($response['data']['themeFilesUpsert'] ?? $response, JSON_PRETTY_PRINT).PHP_EOL;

$errors = $response['data']['themeFilesUpsert']['userErrors'] ?? [];
if ($errors !== []) {
    exit(1);
}

$templateInputs = [];
foreach ($templateFiles as $key) {
    $path = $themeRoot.'/'.$key;
    if (! is_readable($path)) {
        fwrite(STDERR, "Missing: {$path}\n");
        exit(1);
    }
    $templateInputs[] = [
        'filename' => $key,
        'body' => ['type' => 'TEXT', 'value' => file_get_contents($path)],
    ];
}

$templateResponse = $client->query($mutation, [
    'themeId' => 'gid://shopify/OnlineStoreTheme/'.$themeId,
    'files' => $templateInputs,
]);
echo json_encode($templateResponse['data']['themeFilesUpsert'] ?? $templateResponse, JSON_PRETTY_PRINT).PHP_EOL;
$templateErrors = $templateResponse['data']['themeFilesUpsert']['userErrors'] ?? [];
if ($templateErrors !== []) {
    exit(1);
}

$pageQuery = <<<'GQL'
query CompetitionRulesPage {
  pages(first: 5, query: "handle:model-kit-competition-2026-rules") {
    nodes { id handle title templateSuffix isPublished }
  }
}
GQL;

$pageLookup = $client->query($pageQuery);
$existing = $pageLookup['data']['pages']['nodes'][0] ?? null;
echo "PAGE_LOOKUP: ".json_encode($existing ?: $pageLookup, JSON_PRETTY_PRINT).PHP_EOL;

if (is_array($existing) && isset($existing['id'])) {
    $update = <<<'GQL'
    mutation UpdateCompetitionRules($id: ID!, $page: PageUpdateInput!) {
      pageUpdate(id: $id, page: $page) {
        page { id handle title templateSuffix isPublished }
        userErrors { field message }
      }
    }
    GQL;
    $updated = $client->query($update, [
        'id' => $existing['id'],
        'page' => [
            'title' => 'Model Kit Competition 2026 — Rules & Judging',
            'templateSuffix' => 'content',
            'isPublished' => true,
        ],
    ]);
    echo "PAGE_UPDATE: ".json_encode($updated['data']['pageUpdate'] ?? $updated, JSON_PRETTY_PRINT).PHP_EOL;
    exit(($updated['data']['pageUpdate']['userErrors'] ?? []) !== [] ? 1 : 0);
}

$create = <<<'GQL'
mutation CreateCompetitionRules($page: PageCreateInput!) {
  pageCreate(page: $page) {
    page { id handle title templateSuffix isPublished }
    userErrors { field message code }
  }
}
GQL;

$created = $client->query($create, [
    'page' => [
        'title' => 'Model Kit Competition 2026 — Rules & Judging',
        'handle' => 'model-kit-competition-2026-rules',
        'body' => '<p>Rules and judging information for Operation V Studio’s first Model Kit Competition.</p>',
        'templateSuffix' => 'content',
        'isPublished' => true,
    ],
]);
echo "PAGE_CREATE: ".json_encode($created['data']['pageCreate'] ?? $created, JSON_PRETTY_PRINT).PHP_EOL;
exit(($created['data']['pageCreate']['userErrors'] ?? []) !== [] ? 1 : 0);
