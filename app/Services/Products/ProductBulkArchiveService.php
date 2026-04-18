<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;

final class ProductBulkArchiveService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<int, string>  $uuids
     */
    public function setArchivedByUuids(array $uuids, bool $archived): int
    {
        if ($uuids === []) {
            return 0;
        }

        return $this->products->updateByUuids($uuids, [
            'archived_at' => $archived ? now() : null,
        ]);
    }
}
