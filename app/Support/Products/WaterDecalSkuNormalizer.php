<?php

declare(strict_types=1);

namespace App\Support\Products;

final class WaterDecalSkuNormalizer
{
    public const string DEFAULT_VENDOR = 'Water Decals';

    public const string MAIN_TYPE = 'water decals';

    private const string SKU_PREFIX = 'WD-';

    public function normalizeSku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return self::SKU_PREFIX;
        }

        $upper = strtoupper($sku);
        if (str_starts_with($upper, self::SKU_PREFIX)) {
            return $sku;
        }

        return self::SKU_PREFIX.$sku;
    }

    public function proposeDescription(string $currentDescription): string
    {
        $text = trim($currentDescription);
        if ($text === '') {
            return 'Water decal';
        }

        $stripped = preg_replace('/^water\s+decal\s*[-–—:]\s*/iu', '', $text);
        $stripped = is_string($stripped) ? trim($stripped) : $text;

        return 'Water decal - '.$stripped;
    }

    public function isWaterDecalMainType(?string $mainType): bool
    {
        return strtolower(trim((string) $mainType)) === self::MAIN_TYPE;
    }
}
