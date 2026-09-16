<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\DTOs\StorePreorders\StorePreorderMissingPhotosResult;
use App\Services\Products\ProductsRecrawlSelectedService;

final class StorePreorderShopifyImageFollowUpService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly StorePreorderShopifyQueueService $queue,
        private readonly ProductsRecrawlSelectedService $recrawl,
        private readonly StorePreorderPlamodPickListImageAttachService $pickListImages,
        private readonly StorePreorderRealShopifyImagePolicy $realImages,
    ) {}

    public function attachPickListImage(string $productUuid): bool
    {
        return $this->pickListImages->attachIfMissing($productUuid);
    }

    public function afterPlamodPhotos(string $productUuid): void
    {
        $productUuid = trim($productUuid);
        if ($productUuid === '' || ! $this->offers->existsForProductUuid($productUuid)) {
            return;
        }

        $this->realImages->stripPlaceholders($productUuid);
        if (! $this->hasShopifyImages($productUuid)) {
            $this->pickListImages->attachIfMissing($productUuid);
        }
        $this->realImages->stripPlaceholders($productUuid);

        $this->queue->queue(
            [$productUuid],
            $this->hasShopifyImages($productUuid) ? $this->queue->withImages() : $this->queue->imagesOnly(),
        );
    }

    public function queueMissingPhotoCrawls(): StorePreorderMissingPhotosResult
    {
        $withoutImages = $this->productUuidsWithoutRealImages();
        foreach ($withoutImages as $uuid) {
            $this->realImages->stripPlaceholders($uuid);
        }

        $attached = [];
        $stillMissing = [];
        foreach ($withoutImages as $uuid) {
            if ($this->pickListImages->attachIfMissing($uuid)) {
                $attached[] = $uuid;
            } else {
                $stillMissing[] = $uuid;
            }
        }

        $imagePushQueued = $withoutImages === []
            ? 0
            : $this->queue->queue($withoutImages, $this->queue->imagesOnly());
        $queued = $stillMissing === []
            ? 0
            : $this->recrawl->recrawlSelected($stillMissing, ['plamod'])->queued;

        return new StorePreorderMissingPhotosResult(
            count($withoutImages),
            count($attached),
            $imagePushQueued,
            $queued,
        );
    }

    /**
     * @return array{image_push_queued: int, photo_crawl_queued: int}
     */
    public function backfillOpen(): array
    {
        $withImages = [];
        foreach ($this->offers->listOpen() as $offer) {
            $uuid = trim((string) ($offer->product?->uuid ?? ''));
            if ($uuid === '' || ! $this->hasShopifyImages($uuid)) {
                continue;
            }
            $withImages[] = $uuid;
        }

        $photos = $this->queueMissingPhotoCrawls();

        return [
            'image_push_queued' => $withImages === []
                ? 0
                : $this->queue->queue($withImages, $this->queue->withImages()),
            'photo_crawl_queued' => $photos->queued,
        ];
    }

    /**
     * @return list<string>
     */
    private function productUuidsWithoutRealImages(): array
    {
        $withoutImages = [];
        foreach ($this->offers->listAll() as $offer) {
            $uuid = trim((string) ($offer->product?->uuid ?? ''));
            if ($uuid === '' || $this->hasShopifyImages($uuid)) {
                continue;
            }
            $withoutImages[] = $uuid;
        }

        return $withoutImages;
    }

    public function hasShopifyImages(string $productUuid): bool
    {
        return $this->realImages->hasRealImages($productUuid);
    }
}
