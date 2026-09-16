<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\Jobs\ReorderStorePreordersCollectionJob;

final class StorePreorderCollectionReorderScheduler
{
    public function queue(): void
    {
        ReorderStorePreordersCollectionJob::dispatch();
    }
}
