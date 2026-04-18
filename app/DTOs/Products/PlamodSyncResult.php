<?php

declare(strict_types=1);

namespace App\DTOs\Products;

use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;

final class PlamodSyncResult
{
    /**
     * @param  array<int, ProductExternalAsset>  $assets
     */
    public function __construct(
        public bool $backupCreated,
        public ?array $backup,
        public ?ProductExternalContent $content,
        public array $assets,
    ) {}
}
