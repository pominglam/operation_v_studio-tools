<?php

declare(strict_types=1);

namespace App\Support\Products;

final class ProductListFilterCatalog
{
    public const string EMPTY = '__empty__';

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function missingInfo(): array
    {
        return [
            ['value' => 'ok', 'label' => 'OK (complete)'],
            ['value' => 'not_ready', 'label' => 'Not ready'],
            ['value' => 'available_zero', 'label' => 'Available = 0'],
            ['value' => 'maintain_empty', 'label' => 'Maintain qty is empty'],
            ['value' => 'pdp_images', 'label' => 'PDP images'],
            ['value' => 'pdp_description', 'label' => 'PDP description'],
            ['value' => 'selling_price', 'label' => 'Selling price'],
            ['value' => 'barcode', 'label' => 'Barcode'],
            ['value' => 'handle', 'label' => 'Handle'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function productFlags(): array
    {
        return [
            ['value' => 'urgent', 'label' => 'Urgent'],
            ['value' => 'critical', 'label' => 'Critical'],
            ['value' => 'discontinued', 'label' => 'Discontinued'],
            ['value' => 'hazardous_shipment', 'label' => 'Hazardous shipment'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function shipmentMethods(): array
    {
        return [
            ['value' => 'air', 'label' => 'Air'],
            ['value' => 'sea', 'label' => 'Sea'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function ready(): array
    {
        return [
            ['value' => 'all', 'label' => 'All'],
            ['value' => 'ready', 'label' => 'Ready only'],
            ['value' => 'not_ready', 'label' => 'Not ready only'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function archived(): array
    {
        return [
            ['value' => 'active', 'label' => 'Active only'],
            ['value' => 'all', 'label' => 'All (active + archived)'],
            ['value' => 'archived', 'label' => 'Archived only'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function published(): array
    {
        return [
            ['value' => 'all', 'label' => 'All'],
            ['value' => 'published', 'label' => 'Published only'],
            ['value' => 'not_published', 'label' => 'Not published only'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function storePreorder(): array
    {
        return [
            ['value' => 'exclude', 'label' => 'Hide store preorders'],
            ['value' => 'open', 'label' => 'Open store preorders only'],
            ['value' => 'all', 'label' => 'All products'],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function poNovelty(): array
    {
        return [
            ['value' => 'all', 'label' => 'New + existing'],
            ['value' => 'new', 'label' => 'New in selected PO'],
            ['value' => 'existing', 'label' => 'Existing in selected PO'],
        ];
    }
}
