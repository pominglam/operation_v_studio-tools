<?php

declare(strict_types=1);

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const LIVE_THEME_ID = '190542250065';
const LIVE_GIT_BASELINE = 'cfe35b3';

$themeRoot = getenv('THEME_ROOT') ?: realpath(__DIR__.'/../../ovs-shopify-theme');
if (! is_string($themeRoot) || ! is_dir($themeRoot)) {
    fwrite(STDERR, "Refusing: theme root missing — set THEME_ROOT to ovs-shopify-theme\n");
    exit(1);
}

function gitShow(string $repo, string $ref, string $path): string
{
    $command = sprintf('git -C %s show %s:%s', escapeshellarg($repo), escapeshellarg($ref), escapeshellarg($path));
    $output = shell_exec($command);
    if (! is_string($output) || $output === '') {
        throw new RuntimeException("Could not read {$ref}:{$path} from git");
    }

    return $output;
}

/** @var list<string> */
$restoreFromGit = [
    'sections/main-collection-product-grid.liquid',
    'snippets/facets.liquid',
    'assets/facets.js',
];

/** @var list<string> */
$pushFromTheme = [
    'sections/header.liquid',
    'snippets/header-mega-menu.liquid',
    'snippets/header-drawer.liquid',
    'assets/global.js',
];

/** @var list<string> */
$deleteFromLive = [
    'assets/ovs-model-kit-collection-filters-handles.js',
    'assets/ovs-model-kit-collection-filters.js',
    'assets/ovs-model-kit-collection-filters.css',
    'assets/ovs-gundam-uc-collection-filters.js',
    'assets/ovs-gundam-uc-collection-filters.css',
    'assets/ovs-model-kits-mega-menu-poc.js',
    'assets/ovs-model-kits-mega-menu-poc.css',
    'assets/ovs-mobile-nav-drawer.js',
    'assets/ovs-mobile-nav-drawer.css',
    'snippets/ovs-model-kit-collection-filters.liquid',
    'snippets/ovs-model-kit-filter-handles.liquid',
    'snippets/ovs-model-kit-filter-profile.liquid',
    'snippets/ovs-model-kit-filters-params.liquid',
    'snippets/ovs-model-kit-filters-price.liquid',
    'snippets/ovs-model-kit-filters-grade-gunpla.liquid',
    'snippets/ovs-model-kit-filters-series-uc.liquid',
    'snippets/ovs-model-kit-filters-series-au.liquid',
    'snippets/ovs-model-kit-filters-series-gundam.liquid',
    'snippets/ovs-model-kit-filters-flat-group.liquid',
    'snippets/ovs-gundam-uc-collection-filters.liquid',
    'snippets/ovs-model-kits-mega-menu-poc.liquid',
    'snippets/ovs-tools-supplies-mega-menu-poc.liquid',
    'snippets/ovs-miscellaneous-mega-menu-poc.liquid',
];

$inputs = [];

foreach ($restoreFromGit as $filename) {
    $inputs[] = [
        'filename' => $filename,
        'body' => ['type' => 'TEXT', 'value' => gitShow($themeRoot, LIVE_GIT_BASELINE, $filename)],
    ];
}

foreach ($pushFromTheme as $filename) {
    $path = $themeRoot.'/'.$filename;
    if (! is_readable($path)) {
        throw new RuntimeException("Missing theme file: {$path}");
    }
    $inputs[] = [
        'filename' => $filename,
        'body' => ['type' => 'TEXT', 'value' => file_get_contents($path)],
    ];
}

$upsertMutation = <<<'GQL'
mutation themeFilesUpsert($themeId: ID!, $files: [OnlineStoreThemeFilesUpsertFileInput!]!) {
  themeFilesUpsert(themeId: $themeId, files: $files) {
    upsertedThemeFiles { filename }
    userErrors { field message }
  }
}
GQL;

$deleteMutation = <<<'GQL'
mutation themeFilesDelete($themeId: ID!, $files: [String!]!) {
  themeFilesDelete(themeId: $themeId, files: $files) {
    deletedThemeFiles { filename }
    userErrors { field message }
  }
}
GQL;

/** @var ShopifyAdminGraphQlClientInterface $client */
$client = $app->make(ShopifyAdminGraphQlClientInterface::class);
$themeGid = 'gid://shopify/OnlineStoreTheme/'.LIVE_THEME_ID;

$upsertResponse = $client->query($upsertMutation, [
    'themeId' => $themeGid,
    'files' => $inputs,
]);

echo json_encode($upsertResponse, JSON_PRETTY_PRINT).PHP_EOL;

$upsertErrors = $upsertResponse['data']['themeFilesUpsert']['userErrors'] ?? [];
if ($upsertErrors !== []) {
    exit(1);
}

$deleteResponse = $client->query($deleteMutation, [
    'themeId' => $themeGid,
    'files' => $deleteFromLive,
]);

echo json_encode($deleteResponse, JSON_PRETTY_PRINT).PHP_EOL;

$deleteErrors = $deleteResponse['data']['themeFilesDelete']['userErrors'] ?? [];
if ($deleteErrors !== []) {
    fwrite(STDERR, "Delete warnings (non-fatal if files were never on live):\n".json_encode($deleteErrors, JSON_PRETTY_PRINT)."\n");
}

echo "Live Rise reverted to pre-WIP baseline (collection + facets + header gates).\n";
exit(0);
