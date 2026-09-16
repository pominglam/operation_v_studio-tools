<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$themePath = getenv('THEME_ROOT') ?: null;

/** @var App\Services\Storefront\ModelKitCollectionFilterManifestGeneratorService $generator */
$generator = $app->make(App\Services\Storefront\ModelKitCollectionFilterManifestGeneratorService::class);

try {
    $result = $generator->generate(is_string($themePath) && $themePath !== '' ? $themePath : null);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

echo "Wrote {$result->handleCount} handles in {$result->durationMs} ms".PHP_EOL;
foreach ($result->writtenPaths as $path) {
    echo "  {$path}".PHP_EOL;
}
