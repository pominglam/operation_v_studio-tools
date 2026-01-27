<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\ProductExternalContent;

interface ProductExternalContentRepository
{
    public function upsertForProduct(
        int $productId,
        string $source,
        ?string $title,
        ?string $descriptionHtml,
        ?array $attributes,
        ?string $sourceUrl = null,
    ): ProductExternalContent;

    public function findForProduct(int $productId, string $source): ?ProductExternalContent;

    /**
     * @return array<int, ProductExternalContent>
     */
    public function listForProduct(int $productId): array;

    public function updateSourceUrl(int $id, ?string $sourceUrl): void;
}


