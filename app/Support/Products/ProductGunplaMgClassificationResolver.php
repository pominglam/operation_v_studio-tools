<?php

declare(strict_types=1);

namespace App\Support\Products;

final class ProductGunplaMgClassificationResolver
{
    /**
     * Classify Bandai Master Grade family kits from product title/description.
     *
     * Order: MGSD → MGEX → Ver.Ka → standard MG.
     *
     * @return array{type: string, grade: string, subline: string|null}|null
     */
    public function classify(?string $text): ?array
    {
        $text = $text !== null ? trim($text) : '';
        if ($text === '') {
            return null;
        }

        if (preg_match('/\bMGSD\b/i', $text) === 1) {
            return $this->result('MGSD', 'MGSD', 'MGSD');
        }

        if (preg_match('/\bMGEX\b/i', $text) === 1) {
            return $this->result('MGEX', 'MGEX', 'MGEX');
        }

        if (! $this->isMasterGradeKit($text)) {
            return null;
        }

        if ($this->isVerKa($text)) {
            return $this->result('MG', 'MG', 'Ver.Ka');
        }

        return $this->result('MG', 'MG', null);
    }

    public function isMasterGradeKit(string $text): bool
    {
        if (preg_match('/\b(?:ACTION\s+BASE|OPTION\s+PARTS|LED\s+UNIT|DECAL)\b/i', $text) === 1) {
            return false;
        }

        if (preg_match('/\b(?:MGSD|MGEX)\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\bMaster\s+Grade\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\bMG\s+1\s*\/\s*100\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/\bMG\s+(?:MS|MSM|RMS|RX|XXXG|GF|AMX|MBF|RGM|PF|GP|GN|AV|RGB|PMX|MSN|YMS|MB|GD|RGM|PMX|RMS|MSM)\b/i', $text) === 1) {
            return true;
        }

        if (preg_match('/^MG\s+/i', $text) === 1) {
            return true;
        }

        return false;
    }

    public function isVerKa(string $text): bool
    {
        return preg_match('/\bVer\.?\s*Ka\b/i', $text) === 1;
    }

    /**
     * @return array{type: string, grade: string, subline: string|null}
     */
    private function result(string $type, string $grade, ?string $subline): array
    {
        return [
            'type' => $type,
            'grade' => $grade,
            'subline' => $subline,
        ];
    }
}
