<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\Products\ProductRepository;
use App\DAL\StorePreorders\StorePreorderRepository;
use App\Models\StorePreorder;
use App\Services\StorePreorders\Exceptions\StorePreorderCloseException;
use App\Support\StorePreorders\StorePreorderStatus;

final class StorePreorderCloseService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly ProductRepository $products,
        private readonly StorePreorderCollectionReorderScheduler $reorder,
    ) {}

    public function close(string $uuid): StorePreorder
    {
        $offer = $this->offers->findByUuidOrFail($uuid);
        if (! $offer->isOpen()) {
            throw new StorePreorderCloseException('This store preorder is already closed.');
        }

        $product = $offer->product;
        if ($product !== null) {
            $product->available_qty = 0;
            $this->products->save($product);
        }

        $closed = $this->offers->update($offer, [
            'status' => StorePreorderStatus::CLOSED,
            'closed_at' => now(),
        ]);
        $this->reorder->queue();

        return $closed;
    }
}
