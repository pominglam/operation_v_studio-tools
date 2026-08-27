<?php

declare(strict_types=1);

namespace App\Support\Products;

final class ModelKitAccessoryKind
{
    public const DISPLAY_STAND = 'display_stand';

    public const OPTION_PARTS = 'option_parts';

    public const DETAIL_PARTS = 'detail_parts';

    public const SCENE_BASE = 'scene_base';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::DISPLAY_STAND,
            self::OPTION_PARTS,
            self::DETAIL_PARTS,
            self::SCENE_BASE,
        ];
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            self::DISPLAY_STAND => 'Display stand',
            self::OPTION_PARTS => 'Option parts',
            self::DETAIL_PARTS => 'Detail parts',
            self::SCENE_BASE => 'Scene base',
            default => $kind,
        };
    }

    /** @return array<string, string> */
    public static function labelsByValue(): array
    {
        $labels = [];
        foreach (self::values() as $value) {
            $labels[$value] = self::label($value);
        }

        return $labels;
    }
}
