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

        if (preg_match('/\bKERORO\b/i', $name) === 1) {
            return 'KERORO';
        }

        if (preg_match('/\b(?:GUNPLA|ZAKUPLA|CHARZAKU)-KUN(?:\s+DX)?\b/i', $name) === 1) {
            return 'KUN DX';
        }

        if (preg_match('/\bCCS\b/i', $name) === 1) {
            return 'CCS TOYS';
        }

        if (preg_match('/\bSazabi\b.*\bUniversal Century Saga\b/i', $name) === 1) {
            return 'SAZABI BUST';
        }

        if (preg_match('/\bMACROSS\b/i', $name) === 1 || preg_match('/\bVF-\d+[A-Z]?\b/i', $name) === 1) {
            return 'MACROSS';
        }

        if (preg_match('/\bARMORED\s+CORE\b/i', $name) === 1) {
            return 'ARMORED CORE';
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
            '/\bRE\s+1\s*\/\s*100\b/i' => 'RE',
            '/\bFULL\s+MECHANICS\b/i' => 'FM',
            '/\bENTRY\s+GRADE\b/i' => 'EG',
            '/\bOPTION\s+PARTS\s+SET\s+GUNPLA\b/i' => 'OPTION PARTS SET',
            '/\bOPTION\s+PARTS\b/i' => 'OPTION PARTS',
            '/\bKEYCHAIN\b/i' => 'KEYCHAIN',
            '/\b30MP\b/i' => '30MP',
            '/\bPLAMAX\b/i' => 'PLAMAX',
            '/\bACTION\s+BASE\b/i' => 'ACTION BASE',
            '/\bSYSTEM\s+BASE\b/i' => 'SYSTEM BASE',
            '/\bLED\s+UNIT\b/i' => 'LED',
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
        if (preg_match('/^(HGUC|HGBF|HGCE|HGAC|HG|MG|RG|RE|SDW?|SD|30MM|30MF|30MS)\b/i', $name, $m) === 1) {
            return mb_strtoupper((string) $m[1]);
        }

        return null;
    }
}
