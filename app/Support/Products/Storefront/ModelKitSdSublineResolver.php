<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

/**
 * SD mega-menu leaves (plus Sangoku Soketsuden) from ERP type / subline / title.
 */
final class ModelKitSdSublineResolver
{
    public const EX_STANDARD = 'EX-Standard';

    public const CROSS_SILHOUETTE = 'Cross Silhouette';

    public const SD_WORLD_HEROES = 'SDW';

    public const BB_SENSHI = 'BB Senshi';

    public const G_GENERATION = 'G Generation';

    public const BUILD_FIGHTERS = 'SDBF';

    public const GUNPLA_KUN = 'Gunpla-kun';

    public const SANGOKU = 'Sangoku Soketsuden';

    public function resolveLabel(?string $subline, ?string $type, ?string $description, ?string $productLine = null): ?string
    {
        $fromStored = $this->labelFromStored($subline, $type, $productLine);
        if ($fromStored !== null) {
            return $fromStored;
        }

        return $this->labelFromDescription($description);
    }

    public function resolveSlug(?string $subline, ?string $type, ?string $description, ?string $productLine = null): ?string
    {
        $label = $this->resolveLabel($subline, $type, $description, $productLine);

        return $label !== null ? StorefrontTag::slugify($label) : null;
    }

    public function sqlExpression(): string
    {
        return <<<'SQL'
CASE
    WHEN UPPER(TRIM(COALESCE(subline, ''))) IN ('EX-STANDARD', 'EX STANDARD', 'EX_STANDARD')
        OR UPPER(TRIM(COALESCE(type, ''))) IN ('EX-STANDARD', 'EX STANDARD')
        OR UPPER(COALESCE(description, '')) LIKE '%EX-STANDARD%'
        OR UPPER(COALESCE(description, '')) LIKE '%EX STANDARD%'
        THEN 'EX-Standard'
    WHEN UPPER(TRIM(COALESCE(subline, ''))) IN ('CROSS SILHOUETTE', 'CROSS_SILHOUETTE')
        OR UPPER(COALESCE(description, '')) LIKE '%CROSS SILHOUETTE%'
        THEN 'Cross Silhouette'
    WHEN UPPER(TRIM(COALESCE(subline, ''))) IN ('SDW', 'SD WORLD HEROES', 'SDW HEROES')
        OR UPPER(TRIM(COALESCE(type, ''))) = 'SDW'
        OR UPPER(COALESCE(description, '')) LIKE '%SDW HEROES%'
        OR UPPER(COALESCE(description, '')) LIKE '%SD WORLD HEROES%'
        THEN 'SDW'
    WHEN UPPER(TRIM(COALESCE(subline, ''))) IN ('SDBF', 'SD BUILD FIGHTERS')
        OR UPPER(TRIM(COALESCE(type, ''))) = 'SDBF'
        OR UPPER(COALESCE(description, '')) LIKE '%SDBF%'
        THEN 'SDBF'
    WHEN UPPER(TRIM(COALESCE(subline, ''))) IN ('GUNPLA-KUN', 'GUNPLA_KUN')
        OR UPPER(TRIM(COALESCE(type, ''))) = 'KUN DX'
        OR UPPER(TRIM(COALESCE(product_line, ''))) IN ('GUNPLA-KUN', 'GUNPLA KUN')
        THEN 'Gunpla-kun'
    WHEN UPPER(TRIM(COALESCE(subline, ''))) IN ('G GENERATION', 'G-GENERATION', 'G_GENERATION')
        OR UPPER(COALESCE(description, '')) LIKE '%G GENERATION%'
        OR UPPER(COALESCE(description, '')) LIKE '%G-GENERATION%'
        THEN 'G Generation'
    WHEN UPPER(TRIM(COALESCE(subline, ''))) IN ('SANGOKU SOKETSUDEN', 'SANGOKU', 'SANGOKUSOKETSUDEN')
        OR UPPER(COALESCE(description, '')) LIKE '%SANGOKU%'
        THEN 'Sangoku Soketsuden'
    WHEN UPPER(TRIM(COALESCE(subline, ''))) IN ('BB SENSHI', 'BB_SENSHI', 'BB')
        OR UPPER(COALESCE(description, '')) LIKE '%BB SENSHI%'
        OR UPPER(COALESCE(description, '')) LIKE '%LEGENDBB%'
        OR COALESCE(description, '') REGEXP '^BB[0-9]'
        THEN 'BB Senshi'
    ELSE COALESCE(NULLIF(TRIM(subline), ''), '')
END
SQL;
    }

    private function labelFromStored(?string $subline, ?string $type, ?string $productLine): ?string
    {
        $sublineKey = $this->norm($subline);
        $typeKey = $this->norm($type);
        $lineKey = $this->norm($productLine);

        return match (true) {
            $this->isExStandard($sublineKey, $typeKey) => self::EX_STANDARD,
            $this->isCrossSilhouette($sublineKey) => self::CROSS_SILHOUETTE,
            $sublineKey === 'sdw' || $sublineKey === 'sd world heroes' || $typeKey === 'sdw' => self::SD_WORLD_HEROES,
            $sublineKey === 'sdbf' || $sublineKey === 'sd build fighters' || $typeKey === 'sdbf' => self::BUILD_FIGHTERS,
            $sublineKey === 'gunpla-kun' || $sublineKey === 'gunpla_kun' || $typeKey === 'kun dx' || $lineKey === 'gunpla-kun' => self::GUNPLA_KUN,
            $sublineKey === 'g generation' || $sublineKey === 'g-generation' || $sublineKey === 'g_generation' => self::G_GENERATION,
            $sublineKey === 'sangoku soketsuden' || $sublineKey === 'sangoku' => self::SANGOKU,
            $sublineKey === 'bb senshi' || $sublineKey === 'bb_senshi' || $sublineKey === 'bb' => self::BB_SENSHI,
            default => null,
        };
    }

    private function labelFromDescription(?string $description): ?string
    {
        $text = mb_strtoupper(trim((string) $description));
        if ($text === '') {
            return null;
        }

        if (str_contains($text, 'EX-STANDARD') || str_contains($text, 'EX STANDARD')) {
            return self::EX_STANDARD;
        }
        if (str_contains($text, 'CROSS SILHOUETTE')) {
            return self::CROSS_SILHOUETTE;
        }
        if (str_contains($text, 'SDW HEROES') || str_contains($text, 'SD WORLD HEROES')) {
            return self::SD_WORLD_HEROES;
        }
        if (str_contains($text, 'SDBF')) {
            return self::BUILD_FIGHTERS;
        }
        if (str_contains($text, 'G GENERATION') || str_contains($text, 'G-GENERATION')) {
            return self::G_GENERATION;
        }
        if (str_contains($text, 'SANGOKU')) {
            return self::SANGOKU;
        }
        if (str_contains($text, 'BB SENSHI') || str_contains($text, 'LEGENDBB') || preg_match('/^BB\d+/', $text) === 1) {
            return self::BB_SENSHI;
        }

        return null;
    }

    private function isExStandard(string $sublineKey, string $typeKey): bool
    {
        return in_array($sublineKey, ['ex-standard', 'ex standard', 'ex_standard'], true)
            || in_array($typeKey, ['ex-standard', 'ex standard'], true);
    }

    private function isCrossSilhouette(string $sublineKey): bool
    {
        return $sublineKey === 'cross silhouette' || $sublineKey === 'cross_silhouette';
    }

    private function norm(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
