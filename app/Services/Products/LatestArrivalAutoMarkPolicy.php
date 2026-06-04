<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;

final class LatestArrivalAutoMarkPolicy
{
    public function shouldAutoMarkLatestArrival(Product $product): bool
    {
        $mainType = mb_strtolower(trim((string) $product->main_type));

        /** @var array<int, string> $excluded */
        $excluded = config('latest_arrival.exclude_main_types_from_auto_latest_arrival', ['tools']);
        foreach ($excluded as $type) {
            if ($mainType === mb_strtolower(trim((string) $type))) {
                return false;
            }
        }

        return true;
    }
}
