<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

/**
 * Default OVS filter selections when landing on a shelf without URL params.
 *
 * @phpstan-type ShelfDefault array{grades?: list<string>, lines?: list<string>}
 */
final class ModelKitCollectionFilterShelfDefaults
{
    /**
     * @return array<string, ShelfDefault> catalog key → defaults
     */
    public static function catalogKeyDefaults(): array
    {
        return [
            'entry-grade-eg' => ['grades' => ['eg']],
            'high-grade-hg' => ['grades' => ['hg']],
            'real-grade-rg' => ['grades' => ['rg']],
            'perfect-grade-pg' => ['grades' => ['pg']],
            'master-grade-mg' => ['grades' => ['mg']],
            'sd-gundam' => ['grades' => ['sd']],
            'hg-universal-century' => ['grades' => ['hguc']],
            'hg-gundam-seed' => ['grades' => ['hgce']],
            'hg-after-colony' => ['grades' => ['hgac']],
            'hg-iron-blooded-orphans' => ['grades' => ['hgibo']],
            'hg-build-fighters' => ['grades' => ['hgbf']],
            'hg-build-divers' => ['grades' => ['hgbd']],
            'mg-standard' => ['grades' => ['mg-standard']],
            'mg-ver-ka' => ['grades' => ['ver_ka']],
            'mgex' => ['grades' => ['mgex']],
            'mgsd' => ['grades' => ['mgsd']],
            'sd-ex-standard' => ['grades' => ['ex_standard']],
            'sd-cross-silhouette' => ['grades' => ['cross_silhouette']],
            'sd-world-heroes' => ['grades' => ['sdw']],
            'sd-bb-senshi' => ['grades' => ['bb_senshi']],
            'sd-g-generation' => ['grades' => ['g_generation']],
            'sd-build-fighters' => ['grades' => ['sdbf']],
            'sd-gunpla-kun' => ['grades' => ['gunpla_kun']],
            '30-minutes-missions' => ['lines' => ['30mm']],
            '30-minutes-armored-core' => ['lines' => ['30mm_armored_core']],
            '30-minutes-sisters' => ['lines' => ['30ms']],
            '30-minutes-fantasy' => ['lines' => ['30mf']],
            '30-minutes-preference' => ['lines' => ['30mp']],
            '30-minutes-accessories' => ['lines' => ['30mm_accessories']],
        ];
    }

    /**
     * @return array<string, ShelfDefault> Shopify collection handle → defaults
     */
    public static function defaultsByHandle(): array
    {
        $byHandle = [];
        foreach (ModelKitShelfCatalog::shelves() as $catalogKey => $meta) {
            $defaults = self::catalogKeyDefaults()[$catalogKey] ?? null;
            if ($defaults === null) {
                continue;
            }
            $byHandle[$meta['handle']] = $defaults;
        }

        return $byHandle;
    }

    /**
     * Liquid case/when block merged into filter param parsing (server-side checked state).
     */
    public static function liquidAssignCase(): string
    {
        $lines = ['  case collection.handle'];

        foreach (self::defaultsByHandle() as $handle => $defaults) {
            $grades = $defaults['grades'] ?? [];
            $lineDefaults = $defaults['lines'] ?? [];
            if ($grades === [] && $lineDefaults === []) {
                continue;
            }

            $lines[] = "    when '{$handle}'";
            if ($grades !== []) {
                $lines[] = '      if ovs_mk_grades_csv == blank';
                $lines[] = "        assign ovs_mk_grades_csv = '".implode(',', $grades)."'";
                $lines[] = '      endif';
            }
            if ($lineDefaults !== []) {
                $lines[] = '      if ovs_mk_line_csv == blank';
                $lines[] = "        assign ovs_mk_line_csv = '".implode(',', $lineDefaults)."'";
                $lines[] = '      endif';
            }
        }

        $lines[] = '  endcase';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, array{grades?: list<string>, lines?: list<string>}>
     */
    public static function javascriptObject(): array
    {
        return self::defaultsByHandle();
    }

    /**
     * Grade filter key → canonical collection handle (facet “jump” navigation).
     *
     * @return array<string, string>
     */
    public static function gradeKeyCollectionHandles(): array
    {
        return [
            'eg' => 'entry-grade-eg',
            'rg' => 'real-grade-rg',
            'pg' => 'perfect-grade-pg',
            're_100' => 're-100',
            'fm' => 'full-mechanics',
            'mega_size' => 'mega-size',
            'hg' => 'high-grade-hg',
            'sd' => 'sd-gundam',
            'mg' => 'master-grade-mg',
            'hguc' => 'hg-universal-century',
            'hgce' => 'hg-gundam-seed',
            'hgac' => 'hg-after-colony',
            'hgibo' => 'hg-iron-blooded-orphans',
            'hgbf' => 'hg-build-fighters',
            'hgbd' => 'hg-build-divers',
            'mg-standard' => 'mg-standard',
            'ver_ka' => 'mg-ver-ka',
            'mgex' => 'mgex',
            'mgsd' => 'master-grade-sd-mgsd',
            'ex_standard' => 'sd-ex-standard',
            'cross_silhouette' => 'sd-cross-silhouette',
            'sdw' => 'sd-world-heroes',
            'bb_senshi' => 'sd-bb-senshi',
            'g_generation' => 'sd-g-generation',
            'sdbf' => 'sd-build-fighters',
            'gunpla_kun' => 'sd-gunpla-kun',
            'option-parts' => 'gunpla-option-parts',
            'action-base' => 'action-base',
        ];
    }

    /**
     * 30 Minutes line filter key → canonical collection handle.
     *
     * @return array<string, string>
     */
    public static function lineKeyCollectionHandles(): array
    {
        return [
            '30mm' => '30-minutes-missions',
            '30mm_armored_core' => '30-minutes-armored-core',
            '30ms' => '30-minutes-sisters',
            '30mf' => '30-minutes-fantasy',
            '30mp' => '30-minutes-preference',
            '30mm_accessories' => '30-minutes-accessories',
        ];
    }
}
