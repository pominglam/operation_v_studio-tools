<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTOs\Products\ProductTaxonomyProposalDTO;
use App\Models\Product;
use App\Models\ProductExternalContent;

final class ProductTaxonomyEvidenceEnrichmentService
{
    /** @var array<int, string> */
    private const SOURCE_PRIORITY = [
        'bandai',
        'gundamplanet',
        'hlj',
        'newtype',
        'plamod',
        'other',
    ];

    public function enrich(
        Product $product,
        ProductTaxonomyProposalDTO $proposal,
    ): ProductTaxonomyProposalDTO {
        $values = $proposal->values;
        $evidence = $proposal->evidence;
        $sourcedFields = [];

        $source = $this->preferredSource($product);
        if ($source instanceof ProductExternalContent) {
            if ($source->source === 'newtype' && is_array($source->attributes_json) && $source->attributes_json !== []) {
                $sourcedFields = [...$sourcedFields, ...$this->applyNewtypeAttributes($values, $source->attributes_json)];
            }

            $titleFields = $this->applyExternalTitle($values, $source);
            $sourcedFields = [...$sourcedFields, ...$titleFields];

            foreach (array_values(array_unique($sourcedFields)) as $field) {
                if (! isset($evidence[$field])) {
                    continue;
                }
                $evidence[$field] = [
                    ...$evidence[$field],
                    'source_url' => $source->source_url,
                    'source_label' => ucfirst($source->source).' listing',
                    'confidence' => max((int) ($evidence[$field]['confidence'] ?? 0), 85),
                    'notes' => trim(((string) ($evidence[$field]['notes'] ?? '')).' Parsed from cached retailer listing.'),
                ];
            }
        }

        return new ProductTaxonomyProposalDTO(
            $values,
            $evidence,
            $sourcedFields !== [] ? max($proposal->overallConfidence, 85) : $proposal->overallConfidence,
            $proposal->notes,
        );
    }

    private function preferredSource(Product $product): ?ProductExternalContent
    {
        foreach (self::SOURCE_PRIORITY as $sourceName) {
            $match = $product->externalContents->first(
                static fn (ProductExternalContent $source): bool => $source->source === $sourceName
                    && (
                        (is_string($source->source_url) && trim($source->source_url) !== '')
                        || (is_string($source->title) && trim($source->title) !== '')
                    ),
            );
            if ($match instanceof ProductExternalContent) {
                return $match;
            }
        }

        return $product->externalContents->first(
            static fn (ProductExternalContent $source): bool => (is_string($source->source_url) && trim($source->source_url) !== '')
                || (is_string($source->title) && trim($source->title) !== ''),
        );
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<int, string>
     */
    private function applyExternalTitle(array &$values, ProductExternalContent $source): array
    {
        $title = mb_strtoupper(trim((string) $source->title));
        if ($title === '') {
            return [];
        }

        $sourced = [];
        if (str_contains($title, 'STEDI') && ($values['manufacturer'] ?? null) === null) {
            $this->set($values, $sourced, 'manufacturer', 'Stedi');
        }
        if (str_contains($title, 'DSPIAE') && ($values['manufacturer'] ?? null) === null) {
            $this->set($values, $sourced, 'manufacturer', 'Dspiae');
        }
        if (preg_match('/\bMARKERS?\b/', $title) === 1 && ($values['department'] ?? null) === null) {
            $this->set($values, $sourced, 'department', 'supplies');
        }
        if (preg_match('/\b(?:GUNPLA|HGUC|HIGH GRADE|\bHG\b|\bRG\b|\bMG\b)/', $title) === 1
            && preg_match('/\b(?:KEYCHAIN|RUBBER MASCOT|MASCOT KEYCHAIN)\b/', $title) !== 1
            && preg_match('/\bCCS (?:TOYS|EVANGELION)\b/', $title) !== 1
            && ! in_array($values['department'] ?? null, ['misc', 'accessories', 'figures'], true)
        ) {
            $this->set($values, $sourced, 'department', 'model kits');
            $this->set($values, $sourced, 'manufacturer', 'Bandai Spirits');
        }

        return $sourced;
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    private function applyNewtypeAttributes(array &$values, array $attributes): array
    {
        $sourced = [];
        $brand = $this->stringValue($attributes['brand'] ?? null);
        $line = $this->stringValue($attributes['line'] ?? null);

        $this->set($values, $sourced, 'franchise', $this->franchise($brand));
        $this->set($values, $sourced, 'product_line', $this->productLine($line, $brand));
        $this->set($values, $sourced, 'grade', $this->grade($line, $brand));
        $this->set($values, $sourced, 'series', $this->stringValue($attributes['series'] ?? null));
        $this->set($values, $sourced, 'scale', $this->scale($attributes['scale'] ?? null));

        if ($this->isBandaiLine($values['product_line'] ?? null)) {
            $this->set($values, $sourced, 'department', 'model kits');
            $this->set($values, $sourced, 'manufacturer', 'Bandai Spirits');
        }

        return array_values(array_unique($sourced));
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  array<int, string>  $sourced
     */
    private function set(array &$values, array &$sourced, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        $values[$field] = $value;
        $sourced[] = $field;
    }

    private function franchise(?string $brand): ?string
    {
        return match ($brand) {
            'Mobile Suit Gundam' => 'Gundam',
            '30 Minutes Missions', '30 Minutes Sisters', '30 Minutes Fantasy',
            'Megami Device', 'Frame Arms Girl' => null,
            default => $brand,
        };
    }

    private function productLine(?string $line, ?string $brand): ?string
    {
        if ($brand === 'Mobile Suit Gundam' && $this->gradeToken($line) !== null) {
            return 'Gunpla';
        }

        return match ($line) {
            '30 Minutes Missions', '30 Minutes Sisters', '30 Minutes Fantasy',
            'Figure-rise Standard', 'MODEROID' => $line,
            default => null,
        };
    }

    private function grade(?string $line, ?string $brand): ?string
    {
        return $brand === 'Mobile Suit Gundam' ? $this->gradeToken($line) : null;
    }

    private function gradeToken(?string $line): ?string
    {
        if ($line === null || preg_match('/^([A-Z]{2,4})\b/', $line, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function scale(mixed $value): ?string
    {
        $scale = $this->stringValue($value);

        return $scale === 'non' ? 'non-scale' : $scale;
    }

    private function isBandaiLine(?string $productLine): bool
    {
        return in_array($productLine, [
            'Gunpla',
            '30 Minutes Missions',
            '30 Minutes Sisters',
            '30 Minutes Fantasy',
            'Figure-rise Standard',
        ], true);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
