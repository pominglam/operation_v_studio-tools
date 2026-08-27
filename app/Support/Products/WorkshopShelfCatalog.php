<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Support\Products\Storefront\StorefrontDepartment;
use App\Support\Products\Storefront\StorefrontTag;

final class WorkshopShelfCatalog
{
    public static function labelForDepartment(?string $department): ?string
    {
        if ($department === null || trim($department) === '') {
            return null;
        }

        foreach (StorefrontTag::toolsAndSuppliesHubDepartments() as $row) {
            if ($row['slug'] === $department) {
                return $row['label'];
            }
        }

        return null;
    }

    public static function departmentForLabel(?string $label): ?string
    {
        if ($label === null || trim($label) === '') {
            return null;
        }

        foreach (StorefrontTag::toolsAndSuppliesHubDepartments() as $row) {
            if ($row['label'] === $label) {
                return $row['slug'];
            }
        }

        return null;
    }

    public static function canonicalDepartmentFor(?string $storefrontDepartment): ?string
    {
        if ($storefrontDepartment === null || trim($storefrontDepartment) === '') {
            return null;
        }

        return match ($storefrontDepartment) {
            StorefrontDepartment::PAINTS => 'paints',
            StorefrontDepartment::CUTTING,
            StorefrontDepartment::SCRIBING,
            StorefrontDepartment::DRILLS,
            StorefrontDepartment::TWEEZERS,
            StorefrontDepartment::BRUSHES => 'tools',
            StorefrontDepartment::SANDING,
            StorefrontDepartment::TAPES,
            StorefrontDepartment::MARKERS,
            StorefrontDepartment::PANEL_LINERS,
            StorefrontDepartment::DECALS,
            StorefrontDepartment::AIRBRUSH,
            StorefrontDepartment::WEATHERING,
            StorefrontDepartment::ADHESIVES,
            StorefrontDepartment::WORKSHOP_MISC => 'supplies',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function shelfLabels(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['label'],
            StorefrontTag::toolsAndSuppliesHubDepartments(),
        ));
    }
}
