<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DTOs\StorePreorders\StorePreorderOpenResult;
use App\Models\StorePreorder;
use App\Services\Products\ProductsRecrawlSelectedService;

final class StorePreorderOpenPublishService
{
    public function __construct(
        private readonly StorePreorderShopifyQueueService $queue,
        private readonly StorePreorderShopifyImageFollowUpService $images,
        private readonly ProductsRecrawlSelectedService $recrawl,
        private readonly StorePreorderPlamodDescriptionSyncService $descriptions,
    ) {}

    public function afterOpen(StorePreorderOpenResult $result): StorePreorderOpenResult
    {
        $ready = [];
        $needPhotos = [];
        foreach ($result->offers as $offer) {
            if (! $offer instanceof StorePreorder) {
                continue;
            }
            $uuid = trim((string) ($offer->product?->uuid ?? ''));
            if ($uuid === '') {
                continue;
            }
            if ($this->images->hasShopifyImages($uuid) || $this->images->attachPickListImage($uuid)) {
                $ready[] = $uuid;
            } else {
                $needPhotos[] = $uuid;
            }
        }

        $openedUuids = array_values(array_unique([...$ready, ...$needPhotos]));
        if ($openedUuids !== []) {
            $this->descriptions->syncProductUuids($openedUuids);
        }

        $shopifyQueued = 0;
        if ($ready !== []) {
            $shopifyQueued += $this->queue->queue($ready, $this->queue->withImages());
        }
        if ($needPhotos !== []) {
            $shopifyQueued += $this->queue->queue($needPhotos, $this->queue->listingOnly());
            $this->recrawl->recrawlSelected($needPhotos, ['plamod']);
        }

        return new StorePreorderOpenResult(
            $result->offers,
            $result->opened,
            $result->reopened,
            $result->skippedOpen,
            $result->productsCreated,
            $shopifyQueued,
            count($needPhotos),
        );
    }

    public function afterManualOpen(StorePreorderOpenResult $result): StorePreorderOpenResult
    {
        $ready = [];
        $listingOnly = [];
        foreach ($result->offers as $offer) {
            if (! $offer instanceof StorePreorder) {
                continue;
            }
            $uuid = trim((string) ($offer->product?->uuid ?? ''));
            if ($uuid === '') {
                continue;
            }
            if ($this->images->hasShopifyImages($uuid)) {
                $ready[] = $uuid;
            } else {
                $listingOnly[] = $uuid;
            }
        }

        $shopifyQueued = 0;
        if ($ready !== []) {
            $shopifyQueued += $this->queue->queue($ready, $this->queue->withImages());
        }
        if ($listingOnly !== []) {
            $shopifyQueued += $this->queue->queue($listingOnly, $this->queue->listingOnly());
        }

        return new StorePreorderOpenResult(
            $result->offers,
            $result->opened,
            $result->reopened,
            $result->skippedOpen,
            $result->productsCreated,
            $shopifyQueued,
            0,
        );
    }
}
