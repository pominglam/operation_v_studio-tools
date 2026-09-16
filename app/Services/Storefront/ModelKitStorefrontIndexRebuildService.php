<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\DTOs\Storefront\ModelKitStorefrontIndexRebuildResult;
use Illuminate\Support\Facades\Cache;

final class ModelKitStorefrontIndexRebuildService
{
    public function __construct(
        private readonly ModelKitStorefrontIndexBuilderService $builder,
        private readonly ModelKitStorefrontIndexLiquidEncoder $encoder,
        private readonly ModelKitStorefrontIndexThemeWriterService $writer,
    ) {}

    public function rebuild(): ModelKitStorefrontIndexRebuildResult
    {
        $last = null;
        $passes = 0;

        do {
            Cache::forget(ModelKitStorefrontIndexPokeService::DIRTY_KEY);
            $last = $this->writeOnce();
            $passes++;
        } while (Cache::get(ModelKitStorefrontIndexPokeService::DIRTY_KEY) && $passes < 3);

        return new ModelKitStorefrontIndexRebuildResult(
            productCount: $last->productCount,
            bytes: $last->bytes,
            themeIds: $last->themeIds,
            writePasses: $passes,
            generatedAt: $last->generatedAt,
        );
    }

    private function writeOnce(): ModelKitStorefrontIndexRebuildResult
    {
        $document = $this->builder->build();
        $liquid = $this->encoder->encode($document);
        $themeIds = $this->writer->upsert($liquid);

        return new ModelKitStorefrontIndexRebuildResult(
            productCount: count($document->products),
            bytes: strlen($liquid),
            themeIds: $themeIds,
            writePasses: 1,
            generatedAt: $document->generatedAt,
        );
    }
}
