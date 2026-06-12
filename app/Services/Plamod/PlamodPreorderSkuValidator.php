<?php

declare(strict_types=1);

namespace App\Services\Plamod;

final class PlamodPreorderSkuValidator
{
    private const int MAX_LENGTH = 64;

    public static function isValid(string $sku): bool
    {
        $sku = trim($sku);

        if ($sku === '' || strlen($sku) > self::MAX_LENGTH) {
            return false;
        }

        return (bool) preg_match('/^[0-9A-Za-z][0-9A-Za-z_-]*$/', $sku);
    }
}
