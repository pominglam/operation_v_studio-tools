<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Support\Products\ModelKitAccessoryKind;

final class ProductModelKitAccessoryResolver
{
    /**
     * @return array{
     *     accessory_kind: string|null,
     *     product_line: string|null,
     *     scale: string|null,
     *     franchise: string|null,
     *     manufacturer: string|null
     * }
     */
    public function resolve(Product $product, string $searchableText): array
    {
        $empty = [
            'accessory_kind' => null,
            'product_line' => null,
            'scale' => null,
            'franchise' => null,
            'manufacturer' => null,
        ];

        if ($this->isMerchandise($searchableText)) {
            return $empty;
        }

        $legacyType = mb_strtoupper(trim((string) $product->type));
        $sku = mb_strtoupper(trim((string) $product->sku));

        $kind = $this->accessoryKind($searchableText, $legacyType, $sku);
        if ($kind === null) {
            return $empty;
        }

        return [
            'accessory_kind' => $kind,
            'product_line' => $this->productLine($searchableText, $kind, $sku),
            'scale' => $this->scale($searchableText, $kind),
            'franchise' => $this->franchise($searchableText),
            'manufacturer' => $this->manufacturer($searchableText, $sku, $kind),
        ];
    }

    private function isMerchandise(string $text): bool
    {
        return preg_match('/\b(?:KEYCHAIN|RUBBER MASCOT|MASCOT KEYCHAIN)\b/', $text) === 1;
    }

    private function accessoryKind(string $text, string $legacyType, string $sku): ?string
    {
        if ($legacyType === 'ACTION BASE'
            || preg_match('/\b(?:ACTION BASE|WEAPON DISPLAY BASE|LIGHTNING BASE PLATE|SYSTEM BASE)\b/', $text) === 1
            || preg_match('/\bDISPLAY (?:BASE|STAND)\b/', $text) === 1
        ) {
            return ModelKitAccessoryKind::DISPLAY_STAND;
        }

        if (preg_match('/\b(?:CUSTOMIZE SCENE BASE|SCENE BASE)\b/', $text) === 1) {
            return ModelKitAccessoryKind::SCENE_BASE;
        }

        if ($legacyType === 'OPTION PARTS'
            || preg_match('/\b(?:30MS|30MM|30 MINUTES (?:SISTERS|MISSIONS|FANTASY)) OPTION\b/', $text) === 1
            || preg_match('/\bOPTION (?:PARTS SET|ARMOR|BODY PARTS|HAND PARTS)\b/', $text) === 1
            || preg_match('/\b(?:W-2[89]|OPTION WEAPON)\b/', $text) === 1
            || preg_match('/\bOPTION SYSTEM\b/', $text) === 1
            || str_starts_with($sku, 'OP-')
            || str_starts_with($sku, 'WAVOP-')
        ) {
            return ModelKitAccessoryKind::OPTION_PARTS;
        }

        if (str_starts_with($sku, 'BPHD-')
            || preg_match('/\b(?:BUILDERS PARTS|MS HAND|MS SIGHT|SIGHT LENS)\b/', $text) === 1
            || ($legacyType === 'ACCESSORIES' && preg_match('/\b(?:MS HAND|MS SIGHT|SIGHT LENS)\b/', $text) === 1)
        ) {
            return ModelKitAccessoryKind::DETAIL_PARTS;
        }

        return null;
    }

    private function productLine(string $text, string $kind, string $sku): ?string
    {
        return match (true) {
            $kind === ModelKitAccessoryKind::DISPLAY_STAND => 'Action Base',
            preg_match('/\b(?:30MS|30 MINUTES SISTERS)\b/', $text) === 1 => '30 Minutes Sisters',
            preg_match('/\b(?:30MM|30 MINUTES MISSIONS)\b/', $text) === 1 => '30 Minutes Missions',
            preg_match('/\b30 MINUTES FANTASY\b/', $text) === 1 => '30 Minutes Fantasy',
            $kind === ModelKitAccessoryKind::DETAIL_PARTS => 'Builders Parts HD',
            preg_match('/\bOPTION SYSTEM\b/', $text) === 1, str_starts_with($sku, 'OP-'), str_starts_with($sku, 'WAVOP-') => 'Option System',
            preg_match('/\bOPTION PARTS SET\b/', $text) === 1 && str_contains($text, 'GUNDAM') => 'Gunpla',
            default => null,
        };
    }

    private function scale(string $text, string $kind): ?string
    {
        if (preg_match('/\b1\/(10|12|15|20|24|35|48|60|72|100|144)\b/', $text, $match) === 1) {
            return '1/'.$match[1];
        }

        if (in_array($kind, [ModelKitAccessoryKind::OPTION_PARTS, ModelKitAccessoryKind::DETAIL_PARTS, ModelKitAccessoryKind::SCENE_BASE], true)) {
            return '1/144';
        }

        return null;
    }

    private function franchise(string $text): ?string
    {
        return match (true) {
            str_contains($text, 'GUNDAM') => 'Gundam',
            str_contains($text, 'EVANGELION') => 'Evangelion',
            default => null,
        };
    }

    private function manufacturer(string $text, string $sku, string $kind): ?string
    {
        if (preg_match('/\bOPTION SYSTEM\b/', $text) === 1
            || str_starts_with($sku, 'OP-')
            || str_starts_with($sku, 'WAVOP-')
        ) {
            return 'Wave';
        }

        if (str_starts_with($sku, 'BPHD-')
            || preg_match('/\b(?:ACTION BASE|30MS|30MM|BUILDERS PARTS|MS HAND|MS SIGHT)\b/', $text) === 1
            || preg_match('/\b(?:50[0-9]{5}|0[0-9]{6})\b/', $sku) === 1
        ) {
            return 'Bandai Spirits';
        }

        if ($kind === ModelKitAccessoryKind::DISPLAY_STAND && str_contains($text, 'BANDAI')) {
            return 'Bandai Spirits';
        }

        return null;
    }
}
