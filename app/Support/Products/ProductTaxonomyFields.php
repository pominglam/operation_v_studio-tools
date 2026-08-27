<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;

final class ProductTaxonomyFields
{
    /** @var array<int, string> */
    public const CANONICAL = [
        'department',
        'manufacturer',
        'franchise',
        'product_line',
        'subline',
        'grade',
        'series',
        'scale',
    ];

    /** @var array<int, string> */
    public const WORKSHOP = [
        'workshop_shelf',
        'workshop_facets',
    ];

    /** @var array<int, string> */
    public const ACCESSORY = [
        'accessory_kind',
    ];

    /** @var array<int, string> */
    public const ALL = [
        ...self::CANONICAL,
        ...self::WORKSHOP,
        ...self::ACCESSORY,
    ];

    /**
     * @return array<string, string|array<string, string|array<int, string>>|null>
     */
    public static function fromProduct(Product $product): array
    {
        $values = [];
        foreach (self::CANONICAL as $field) {
            $value = $product->getAttribute($field);
            $values[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        $shelf = $product->getAttribute('workshop_shelf');
        $values['workshop_shelf'] = is_string($shelf) && trim($shelf) !== '' ? trim($shelf) : null;
        $values['workshop_facets'] = self::normalizeFacets($product->getAttribute('workshop_facets'));

        $accessoryKind = $product->getAttribute('accessory_kind');
        $values['accessory_kind'] = is_string($accessoryKind) && trim($accessoryKind) !== '' ? trim($accessoryKind) : null;

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|array<string, string|array<int, string>>|null>
     */
    public static function normalize(array $values): array
    {
        $normalized = [];
        foreach (self::CANONICAL as $field) {
            $value = $values[$field] ?? null;
            $normalized[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        $shelf = $values['workshop_shelf'] ?? null;
        $normalized['workshop_shelf'] = is_string($shelf) && trim($shelf) !== '' ? trim($shelf) : null;
        $normalized['workshop_facets'] = self::normalizeFacets($values['workshop_facets'] ?? null);

        $accessoryKind = $values['accessory_kind'] ?? null;
        $normalized['accessory_kind'] = is_string($accessoryKind) && trim($accessoryKind) !== '' ? trim($accessoryKind) : null;

        return $normalized;
    }

    public static function valuesDiffer(array $left, array $right): bool
    {
        return json_encode(self::normalize($left)) !== json_encode(self::normalize($right));
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public static function normalizeFacets(mixed $facets): array
    {
        if (! is_array($facets)) {
            return [];
        }

        /** @var array<string, string|array<int, string>> $normalized */
        $normalized = [];
        foreach ($facets as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $normalized[$key] = trim($value);

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $list = array_values(array_unique(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            ), static fn (string $item): bool => $item !== '')));

            if ($list !== []) {
                $normalized[$key] = $list;
            }
        }

        ksort($normalized);

        return $normalized;
    }
}
