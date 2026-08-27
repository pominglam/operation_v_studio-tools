<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductTaxonomyRepository;
use App\Models\ProductTaxonomyVerification;
use App\Support\Products\ProductTaxonomyFields;

final class ProductTaxonomyConfirmationExportService
{
    public function __construct(
        private readonly ProductTaxonomyRepository $taxonomy,
    ) {}

    /**
     * @return list<string>
     */
    public function header(): array
    {
        return [
            'sku',
            'title',
            'archived',
            'status',
            'confidence',
            'department',
            'manufacturer',
            'franchise',
            'product_line',
            'subline',
            'grade',
            'series',
            'scale',
            'workshop_shelf',
            'workshop_facets',
            'accessory_kind',
            'missing_fields',
            'notes',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<int, string>>
     */
    public function rows(array $filters): array
    {
        $rows = [];
        foreach ($this->taxonomy->listVerifications($filters) as $verification) {
            $rows[] = $this->row($verification);
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function row(ProductTaxonomyVerification $verification): array
    {
        $values = ProductTaxonomyFields::normalize($verification->proposed_values_json);
        $missing = [];
        foreach (ProductTaxonomyFields::CANONICAL as $field) {
            if ($values[$field] === null) {
                $missing[] = $field;
            }
        }

        return [
            (string) $verification->product->sku,
            (string) $verification->product->description,
            $verification->product->archived_at === null ? '0' : '1',
            (string) $verification->status,
            (string) $verification->overall_confidence,
            (string) ($values['department'] ?? ''),
            (string) ($values['manufacturer'] ?? ''),
            (string) ($values['franchise'] ?? ''),
            (string) ($values['product_line'] ?? ''),
            (string) ($values['subline'] ?? ''),
            (string) ($values['grade'] ?? ''),
            (string) ($values['series'] ?? ''),
            (string) ($values['scale'] ?? ''),
            (string) ($values['workshop_shelf'] ?? ''),
            json_encode($values['workshop_facets'] ?? [], JSON_THROW_ON_ERROR),
            (string) ($values['accessory_kind'] ?? ''),
            implode('|', $missing),
            (string) ($verification->operator_notes ?? ''),
        ];
    }
}
