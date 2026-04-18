<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\ProductExternalContent;

final class EloquentProductExternalContentRepository implements ProductExternalContentRepository
{
    public function upsertForProduct(
        int $productId,
        string $source,
        ?string $title,
        ?string $descriptionHtml,
        ?array $attributes,
        ?string $sourceUrl = null,
    ): ProductExternalContent {
        /** @var ProductExternalContent $content */
        $content = ProductExternalContent::query()->updateOrCreate(
            ['product_id' => $productId, 'source' => $source],
            [
                'source_url' => $sourceUrl,
                'title' => $title,
                'description_html' => $descriptionHtml,
                'attributes_json' => $attributes,
            ],
        );

        return $content;
    }

    public function findForProduct(int $productId, string $source): ?ProductExternalContent
    {
        /** @var ProductExternalContent|null $content */
        $content = ProductExternalContent::query()
            ->where('product_id', $productId)
            ->where('source', $source)
            ->first();

        return $content;
    }

    public function listForProduct(int $productId): array
    {
        /** @var array<int, ProductExternalContent> $rows */
        $rows = ProductExternalContent::query()
            ->where('product_id', $productId)
            ->orderBy('source')
            ->orderBy('id')
            ->get()
            ->all();

        return $rows;
    }

    public function updateSourceUrl(int $id, ?string $sourceUrl): void
    {
        ProductExternalContent::query()
            ->whereKey($id)
            ->update(['source_url' => $sourceUrl]);
    }
}
