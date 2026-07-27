<?php

declare(strict_types=1);

namespace App\Support\Products;

use Illuminate\Support\Str;

final class ShopifyHandleSlugger
{
    /** @var array<string, string> */
    private const UNICODE_ROMAN = [
        'Ⅹ' => 'x',
        'Ⅸ' => 'ix',
        'Ⅷ' => 'viii',
        'Ⅶ' => 'vii',
        'Ⅵ' => 'vi',
        'Ⅴ' => 'v',
        'Ⅳ' => 'iv',
        'Ⅲ' => 'iii',
        'Ⅱ' => 'ii',
        'Ⅰ' => 'i',
        'ⅹ' => 'x',
        'ⅸ' => 'ix',
        'ⅷ' => 'viii',
        'ⅶ' => 'vii',
        'ⅵ' => 'vi',
        'ⅴ' => 'v',
        'ⅳ' => 'iv',
        'ⅲ' => 'iii',
        'ⅱ' => 'ii',
        'ⅰ' => 'i',
    ];

    /** @var list<string> */
    private const ASCII_ROMAN = [
        'XIII', 'XII', 'XI', 'IX', 'VIII', 'VII', 'VI', 'IV', 'III', 'II', 'I',
    ];

    public static function normalizeRomanNumerals(string $text): string
    {
        $text = strtr($text, self::UNICODE_ROMAN);

        foreach (self::ASCII_ROMAN as $numeral) {
            $pattern = '/\b'.preg_quote($numeral, '/').'\b/u';
            $replaced = preg_replace($pattern, strtolower($numeral), $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }

    public static function slugFromText(string $text, ?string $skuFallback = null): string
    {
        $normalized = self::normalizeRomanNumerals($text);
        $base = Str::slug($normalized);
        if ($base === '' && $skuFallback !== null) {
            $base = Str::slug($skuFallback);
        }
        if ($base === '') {
            $base = 'product';
        }

        return $base;
    }
}
