<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class WeatheringProductResolver
{
    public function belongsToWeatheringDepartment(Product $product): bool
    {
        $sku = strtoupper(trim((string) $product->sku));
        $type = strtoupper(trim((string) ($product->type ?? '')));
        $description = strtolower(trim((string) $product->description));

        if ($type === 'WEATHERING') {
            return true;
        }

        if (preg_match('/^MP-5\d/', $sku) === 1 && str_contains($description, 'weathering')) {
            return true;
        }

        return str_contains($description, 'weathering')
            && str_contains($description, 'stedi');
    }
}
