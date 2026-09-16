<?php

declare(strict_types=1);

namespace App\Jobs\Shopify;

use App\Services\Storefront\ModelKitStorefrontIndexRebuildService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class RebuildModelKitStorefrontIndexJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'shopify';

    public const string UNIQUE_ID = 'storefront-mk-index-rebuild';

    public int $timeout = 180;

    public int $tries = 2;

    public int $uniqueFor = 180;

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return self::UNIQUE_ID;
    }

    public function handle(ModelKitStorefrontIndexRebuildService $rebuild): void
    {
        $result = $rebuild->rebuild();

        Log::info('storefront.mk_index.rebuilt', [
            'product_count' => $result->productCount,
            'bytes' => $result->bytes,
            'theme_ids' => $result->themeIds,
            'write_passes' => $result->writePasses,
            'generated_at' => $result->generatedAt,
        ]);
    }
}
