<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Jobs\Shopify\RebuildModelKitStorefrontIndexJob;
use App\Models\Product;
use App\Support\Products\Storefront\ModelKitStorefrontTagResolver;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Support\Facades\Cache;

final class ModelKitStorefrontIndexPokeService
{
    public const string DIRTY_KEY = 'storefront:mk-index:dirty';

    public function __construct(
        private readonly ModelKitStorefrontTagResolver $tags,
    ) {}

    public function pokeForProduct(Product $product): void
    {
        if (! $this->affectsModelKitIndex($product)) {
            return;
        }

        $this->poke();
    }

    public function poke(): void
    {
        if (! (bool) config('shopify.mk_storefront_index.enabled', true)) {
            return;
        }

        Cache::put(self::DIRTY_KEY, true, now()->addMinutes(30));

        $delay = max(0, (int) config('shopify.mk_storefront_index.poke_delay_seconds', 8));
        RebuildModelKitStorefrontIndexJob::dispatch()->delay(now()->addSeconds($delay));
    }

    private function affectsModelKitIndex(Product $product): bool
    {
        if (in_array(StorefrontTag::MK_DEPT_MODEL_KITS, $this->tags->tagsForProduct($product), true)) {
            return true;
        }

        $department = mb_strtolower(trim((string) $product->department));
        $mainType = mb_strtolower(trim((string) $product->main_type));

        return $department === 'model kits' || $mainType === 'model kit';
    }
}
