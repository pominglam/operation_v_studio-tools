<?php

declare(strict_types=1);

namespace App\Support\Products;

final class ProductExternalAssetUrlBuilder
{
    public static function viewUrl(int $id): string
    {
        return '/api/v1/product-assets/'.$id.'/view';
    }

    public static function downloadUrl(int $id): string
    {
        return '/api/v1/product-assets/'.$id.'/download';
    }

    public static function thumbUrl(int $id): string
    {
        return '/api/v1/product-assets/'.$id.'/thumb';
    }
}
