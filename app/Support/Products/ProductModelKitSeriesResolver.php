<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;

final class ProductModelKitSeriesResolver
{
    public function resolve(Product $product, string $searchableText): ?string
    {
        $existing = trim((string) ($product->series ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $text = mb_strtoupper($searchableText);

        return match (true) {
            str_contains($text, 'GQUUUUUUX') || str_contains($text, 'GQUUUUUUUX') => 'Mobile Suit Gundam GQuuuuuuX',
            str_contains($text, 'WITCH FROM MERCURY') || str_contains($text, 'THE WFM') => 'The Witch From Mercury',
            str_contains($text, 'SEED DESTINY') => 'Gundam Seed Destiny',
            str_contains($text, 'SEED FREEDOM') => 'Gundam Seed Freedom',
            str_contains($text, 'SEED ASTRAY') => 'Gundam Seed Astray',
            preg_match('/\b(?:GUNDAM )?SEED\b/', $text) === 1 => 'Gundam Seed',
            str_contains($text, 'IRON-BLOODED ORPHANS')
                || str_contains($text, 'IRON BLOODED ORPHANS')
                || preg_match('/\bIBO\b/', $text) === 1 => 'Iron-Blooded Orphans',
            preg_match('/\bGUNDAM 00\b/', $text) === 1
                || preg_match('/\b00 (?:RAISER|QAN\[T\]|EXIA|DYNAMES|KYRIOS|VIRTUE)\b/', $text) === 1 => 'Gundam 00',
            str_contains($text, 'ENDLESS WALTZ') => 'Gundam Wing: Endless Waltz',
            preg_match('/\bGUNDAM WING\b/', $text) === 1
                || preg_match('/\bWING GUNDAM\b/', $text) === 1 => 'Gundam Wing',
            str_contains($text, 'BUILD DIVERS') => 'Gundam Build Divers',
            str_contains($text, 'BUILD FIGHTERS') => 'Gundam Build Fighters',
            str_contains($text, 'UNICORN') => 'Gundam Unicorn',
            str_contains($text, 'CHAR\'S COUNTERATTACK') || str_contains($text, 'NU GUNDAM') => 'Char\'s Counterattack',
            str_contains($text, 'ZETA GUNDAM') => 'Zeta Gundam',
            str_contains($text, 'GUNDAM ZZ') => 'Gundam ZZ',
            str_contains($text, '0083') => 'Gundam 0083: Stardust Memory',
            str_contains($text, '0080') => 'Gundam 0080: War in the Pocket',
            str_contains($text, '08TH MS TEAM') => 'The 08th MS Team',
            str_contains($text, 'ARMORED CORE') => 'Armored Core VI: Fires Of Rubicon',
            default => null,
        };
    }
}
