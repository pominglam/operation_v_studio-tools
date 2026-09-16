<?php

declare(strict_types=1);

/**
 * Thin wrapper — prefer: php artisan products:model-kit-series-audit
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

exit($kernel->call('products:model-kit-series-audit', [], null));
