<?php

declare(strict_types=1);

namespace App\Services\Products;

final class ProductTypeDerivationService
{
    public function deriveFromName(?string $name): ?string
    {
        $name = $name !== null ? trim($name) : '';
        if ($name === '') {
            return null;
        }

        // Specific vendor/product naming quirks.
        if (preg_match('/\bORPHANS\s+HG\b/i', $name) === 1) {
            return 'Orphans HG';
        }

        if (preg_match('/^BB/i', $name) === 1) {
            return 'SD';
        }

        $rules = [
            '/\bMGSD\b/i' => 'MGSD',
            '/\bSDBF\b/i' => 'SDBF',
            '/(?:^|\s)SD(?:\s|$)/i' => 'SD',
            '/\bMGEX\b/i' => 'MGEX',
            '/\bPG\b/i' => 'PG',
            '/\bHGBD\b/i' => 'HGBD',
            '/\bHGFC\b/i' => 'HGFC',
            '/\bHGBC\b/i' => 'HGBC',
            '/\bHGAC\b/i' => 'HGAC',
            '/\bHGUC\b/i' => 'HGUC',
            '/\bHGAW\b/i' => 'HGAW',
            '/\bFULL\s+MECHANICS\b/i' => 'FM',
            '/\bENTRY\s+GRADE\b/i' => 'EG',
            '/\bOPTION\s+PARTS\b/i' => 'OPTION PARTS',
            '/\bKEYCHAIN\b/i' => 'KEYCHAIN',
            '/\b30MP\b/i' => '30MP',
            '/\bPLAMAX\b/i' => 'PLAMAX',
            '/\bACTION\s+BASE\b/i' => 'ACTION BASE',
            '/\bNIPPER\b/i' => 'NIPPER',
            '/\bSANDING\b/i' => 'SANDING',
            '/\bMEGA\s+SIZE\b/i' => 'MEGA',
            '/\bFIGURE-?RISE\b/i' => 'Figure-rise',
            '/\bEX[-\s]?STANDARD\b/i' => 'EX-Standard',
            '/\bPOK(?:É|E)MON\b/iu' => 'POKEMON',
        ];

        foreach ($rules as $pattern => $type) {
            if (preg_match($pattern, $name) === 1) {
                return $type;
            }
        }

        // Generic model-grade prefixes.
        if (preg_match('/^(HGUC|HGBF|HGCE|HGAC|HG|MG|RG|SDW?|SD|30MM)\b/i', $name, $m) === 1) {
            return mb_strtoupper((string) $m[1]);
        }

        return null;
    }
}
