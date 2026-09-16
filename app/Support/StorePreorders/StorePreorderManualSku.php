<?php

declare(strict_types=1);

namespace App\Support\StorePreorders;

use Illuminate\Support\Str;

final class StorePreorderManualSku
{
    public static function fromName(string $name): string
    {
        $cleaned = trim($name);
        $cleaned = preg_replace('/\((?:pre-?order|po)\)/i', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\bpre-?orders?\b/i', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\bmodel kits?\b/i', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\beta\b[:\s-]*[a-z]{3,9}\.?\s*\d{4}/i', ' ', $cleaned) ?? $cleaned;
        $cleaned = str_replace(['/', '\\'], '-', $cleaned);
        $slug = Str::slug($cleaned);
        if ($slug === '') {
            $slug = 'kit';
        }

        $sku = 'OVS-'.$slug;
        if (strlen($sku) > 64) {
            $sku = rtrim(substr($sku, 0, 64), '-');
        }

        return $sku;
    }
}
