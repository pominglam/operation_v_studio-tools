<?php

declare(strict_types=1);

use App\Jobs\SyncPlamodAssetsJob;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;

it('skips execution for cancelled batches', function (): void {
    $job = new SyncPlamodAssetsJob('sync-1', 'product-1');

    $mw = $job->middleware();
    expect($mw)->toBeArray();
    expect(array_filter($mw, fn ($m): bool => $m instanceof SkipIfBatchCancelled))->not->toBeEmpty();
});


