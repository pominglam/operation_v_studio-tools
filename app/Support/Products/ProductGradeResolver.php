<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;
use App\Services\Products\ProductTypeDerivationService;

final class ProductGradeResolver
{
    /** @var array<string, string> */
    private const TYPE_TO_GRADE = [
        'EG' => 'EG',
        'ENTRY GRADE' => 'EG',
        'HG' => 'HG',
        'HGUC' => 'HG',
        'HGBF' => 'HG',
        'HGCE' => 'HG',
        'HGAC' => 'HG',
        'HGFC' => 'HG',
        'HGBC' => 'HG',
        'HGAW' => 'HG',
        'HGBD' => 'HG',
        'Orphans HG' => 'HG',
        'HGIBO' => 'HG',
        'RG' => 'RG',
        'MG' => 'MG',
        'MGEX' => 'MGEX',
        'MGSD' => 'MGSD',
        'PG' => 'PG',
        'MEGA' => 'MEGA',
        'FM' => 'FM',
        'RE' => 'RE',
        'SD' => 'SD',
        'BB' => 'SD',
        'SDW' => 'SD',
        'SDBF' => 'SD',
        'EX-Standard' => 'SD',
        'NG' => 'NG',
    ];

    /** @var array<string, string> */
    private const DERIVED_TYPE_TO_GRADE = [
        'EG' => 'EG',
        'ENTRY GRADE' => 'EG',
        'HG' => 'HG',
        'HGUC' => 'HG',
        'HGBF' => 'HG',
        'HGCE' => 'HG',
        'HGAC' => 'HG',
        'HGFC' => 'HG',
        'HGBC' => 'HG',
        'HGAW' => 'HG',
        'HGBD' => 'HG',
        'Orphans HG' => 'HG',
        'HGIBO' => 'HG',
        'RG' => 'RG',
        'MG' => 'MG',
        'MGEX' => 'MGEX',
        'MGSD' => 'MGSD',
        'PG' => 'PG',
        'MEGA' => 'MEGA',
        'FM' => 'FM',
        'RE' => 'RE',
        'SD' => 'SD',
        'BB' => 'SD',
        'SDW' => 'SD',
        'SDBF' => 'SD',
        'EX-Standard' => 'SD',
    ];

    public function __construct(
        private readonly ProductTypeDerivationService $typeDerivation,
    ) {}

    public function resolveFromProduct(Product $product): ?string
    {
        $storedGrade = $this->normalizeStoredGrade($product->grade);
        if ($storedGrade === 'NG') {
            return 'NG';
        }

        $fromType = $this->resolveFromType($product->type);
        if ($fromType !== null) {
            return $fromType;
        }

        $fromDescription = $this->resolveFromDescription($product->description);
        if ($fromDescription !== null) {
            return $fromDescription;
        }

        return $storedGrade;
    }

    public function resolveFromType(?string $type): ?string
    {
        $type = $type !== null ? trim($type) : '';
        if ($type === '') {
            return null;
        }

        return self::TYPE_TO_GRADE[$type] ?? null;
    }

    public function resolveFromDescription(?string $description): ?string
    {
        $description = $description !== null ? trim($description) : '';
        if ($description === '') {
            return null;
        }

        if (preg_match('/\bENTRY\s+GRADE\b/i', $description) === 1) {
            return 'EG';
        }
        if (preg_match('/\bFULL\s+MECHANICS\b/i', $description) === 1) {
            return 'FM';
        }
        if (preg_match('/\bMGEX\b/i', $description) === 1) {
            return 'MGEX';
        }
        if (preg_match('/\bMGSD\b/i', $description) === 1) {
            return 'MGSD';
        }
        if (preg_match('/\bEX[-\s]?STANDARD\b/i', $description) === 1) {
            return 'SD';
        }
        if (preg_match('/\bMEGA\s+SIZE\b/i', $description) === 1) {
            return 'MEGA';
        }
        if (preg_match('/\b(?:^|\s)SD(?:\s|$)/i', $description) === 1 || preg_match('/\bBB\d+/i', $description) === 1) {
            return 'SD';
        }
        if (preg_match('/\bPG\b/i', $description) === 1) {
            return 'PG';
        }
        if (preg_match('/\bRG\b/i', $description) === 1) {
            return 'RG';
        }
        if (preg_match('/\bMG\b/i', $description) === 1) {
            return 'MG';
        }
        if (preg_match('/\bHGUC\b/i', $description) === 1 || preg_match('/\b(?:^|\s)HG(?:\s|$|\d)/i', $description) === 1) {
            return 'HG';
        }

        $derivedType = $this->typeDerivation->deriveFromName($description);

        return $derivedType !== null ? (self::DERIVED_TYPE_TO_GRADE[$derivedType] ?? null) : null;
    }

    public function needsCorrection(Product $product): bool
    {
        $resolved = $this->resolveFromProduct($product);
        if ($resolved === null) {
            return false;
        }

        $current = $this->normalizeStoredGrade($product->grade);

        return $current !== $resolved;
    }

    public function scaleForGrade(?string $grade): ?string
    {
        $grade = $this->normalizeStoredGrade($grade);
        if ($grade === null) {
            return null;
        }

        return match ($grade) {
            'MG', 'MGEX', 'MGSD', 'FM' => '1/100',
            'HG', 'RG', 'EG', 'NG' => '1/144',
            'PG' => '1/60',
            'MEGA' => '1/48',
            default => null,
        };
    }

    private function normalizeStoredGrade(?string $grade): ?string
    {
        $grade = $grade !== null ? mb_strtoupper(trim($grade)) : '';
        if ($grade === '') {
            return null;
        }

        return match ($grade) {
            'ENTRY GRADE' => 'EG',
            'EX-STANDARD' => 'SD',
            default => $grade,
        };
    }
}
