<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Support\Products\Storefront\ModelKitCollectionFilterGunplaPool;
use App\Support\Products\Storefront\ModelKitCollectionFilterHubNavigation;
use App\Support\Products\Storefront\ModelKitCollectionFilterProfileCatalog;
use App\Support\Products\Storefront\ModelKitCollectionFilterShelfDefaults;
use App\Support\Products\Storefront\ModelKitShelfCatalog;
use App\Support\Storefront\ModelKitCollectionFilterManifestResult;
use RuntimeException;

final class ModelKitCollectionFilterManifestGeneratorService
{
    public function generate(?string $themeRoot = null): ModelKitCollectionFilterManifestResult
    {
        $startedAt = hrtime(true);
        $resolvedThemeRoot = $this->resolveThemeRoot($themeRoot);
        $profilesByHandle = ModelKitCollectionFilterProfileCatalog::handlesByProfile();
        $profileGroups = ModelKitCollectionFilterProfileCatalog::profileGroups();
        $groupLabels = ModelKitCollectionFilterProfileCatalog::groupLabels();
        $urlParams = ModelKitCollectionFilterProfileCatalog::urlParams();
        $shelves = ModelKitShelfCatalog::shelves();

        $writtenPaths = [];
        $writtenPaths[] = $this->writeJsonManifest(
            $resolvedThemeRoot,
            $profilesByHandle,
            $profileGroups,
            $groupLabels,
            $urlParams,
            $shelves,
        );
        $writtenPaths[] = $this->writeHandlesLiquid($resolvedThemeRoot, $profilesByHandle);
        $writtenPaths[] = $this->writeGridSectionHandles($resolvedThemeRoot, $profilesByHandle);
        $writtenPaths[] = $this->writeFacetsSectionHandles($resolvedThemeRoot, $profilesByHandle);
        $writtenPaths[] = $this->writeCollectionNavHandles($resolvedThemeRoot, $profilesByHandle);
        $writtenPaths[] = $this->writeProfileLiquid($resolvedThemeRoot, $profilesByHandle);
        $writtenPaths[] = $this->writeHandlesJavascript($resolvedThemeRoot, $profilesByHandle);
        $writtenPaths[] = $this->writeMegaMenuHubUrls($resolvedThemeRoot);
        $writtenPaths[] = $this->writeRequirementsDoc(
            $profilesByHandle,
            $profileGroups,
            $groupLabels,
            $urlParams,
            $shelves,
        );

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        return new ModelKitCollectionFilterManifestResult(
            handleCount: count($profilesByHandle),
            durationMs: $durationMs,
            themeRoot: $resolvedThemeRoot,
            writtenPaths: $writtenPaths,
        );
    }

    public function resolveThemeRoot(?string $themeRoot = null): string
    {
        if (is_string($themeRoot) && $themeRoot !== '') {
            $resolved = realpath($themeRoot);
            if ($resolved === false) {
                throw new RuntimeException("Theme path not found: {$themeRoot}");
            }

            return $resolved;
        }

        $fromEnv = config('storefront_classification.ovs_shopify_theme_path');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $resolved = realpath($fromEnv);
            if ($resolved !== false) {
                return $resolved;
            }
        }

        $sibling = realpath(base_path('../ovs-shopify-theme'));
        if ($sibling === false) {
            throw new RuntimeException(
                'Could not locate ovs-shopify-theme. Set OVS_SHOPIFY_THEME_PATH in .env or mount the theme repo.',
            );
        }

        return $sibling;
    }

    /**
     * @param  array<string, string>  $profilesByHandle
     * @param  array<string, list<string>>  $profileGroups
     * @param  array<string, string>  $groupLabels
     * @param  array<string, string>  $urlParams
     * @param  array<string, array{handle: string, title: string, tag?: string, tags?: list<string>, disjunctive?: bool}>  $shelves
     */
    private function writeJsonManifest(
        string $themeRoot,
        array $profilesByHandle,
        array $profileGroups,
        array $groupLabels,
        array $urlParams,
        array $shelves,
    ): string {
        $jsonPath = $themeRoot.'/docs/model-kit-collection-filters.json';
        if (! is_dir(dirname($jsonPath)) && ! mkdir(dirname($jsonPath), 0777, true) && ! is_dir(dirname($jsonPath))) {
            throw new RuntimeException('Could not create directory: '.dirname($jsonPath));
        }

        $payload = [
            'version' => 1,
            'availability' => 'native-shopify-last',
            'profileGroups' => $profileGroups,
            'groupLabels' => $groupLabels,
            'urlParams' => $urlParams,
            'handles' => $profilesByHandle,
            'shelves' => array_map(
                static fn (array $meta): array => ['handle' => $meta['handle'], 'title' => $meta['title']],
                array_values($shelves),
            ),
        ];

        file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $jsonPath;
    }

    /**
     * @param  array<string, string>  $profilesByHandle
     */
    private function writeHandlesLiquid(string $themeRoot, array $profilesByHandle): string
    {
        $path = $themeRoot.'/snippets/ovs-model-kit-filter-handles.liquid';
        $handlesCsv = implode(',', array_keys($profilesByHandle));
        $gunplaPoolCsv = ModelKitCollectionFilterGunplaPool::handlesCsv();
        $contents = "{%- comment -%} Generated by model-kit filter manifest generator — do not edit by hand. {%- endcomment -%}\n";
        $contents .= "{%- assign ovs_mk_filter_handles_csv = '{$handlesCsv}' -%}\n";
        $contents .= "{%- assign ovs_mk_gunpla_pool_handles_csv = '{$gunplaPoolCsv}' -%}\n";
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Inline handle CSV in main-collection-product-grid (Shopify render scope isolates snippet assigns).
     *
     * @param  array<string, string>  $profilesByHandle
     */
    private function writeGridSectionHandles(string $themeRoot, array $profilesByHandle): string
    {
        $path = $themeRoot.'/sections/main-collection-product-grid.liquid';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read grid section: {$path}");
        }

        $handlesCsv = implode(',', array_keys($profilesByHandle));
        $gunplaPoolCsv = ModelKitCollectionFilterGunplaPool::handlesCsv();
        $block = "{%- comment -%} Generated by model-kit filter manifest generator — inline in parent (render scope). {%- endcomment -%}\n"
            ."{%- assign ovs_mk_filter_handles_csv = '{$handlesCsv}' -%}\n"
            ."{%- assign ovs_mk_gunpla_pool_handles_csv = '{$gunplaPoolCsv}' -%}";

        $pattern = '/\s*\{%- comment -%\} Generated by model-kit filter manifest generator — inline in parent \(render scope\)\. \{%- endcomment -%\}\s*\{%- assign ovs_mk_filter_handles_csv = \'[^\']*\' -%\}\s*\{%- assign ovs_mk_gunpla_pool_handles_csv = \'[^\']*\' -%\}/';
        $updated = preg_replace($pattern, $block, $contents, -1, $replaceCount);
        if ($replaceCount !== 2) {
            throw new RuntimeException(
                "Expected 2 MK filter handle blocks in main-collection-product-grid, updated {$replaceCount}",
            );
        }

        file_put_contents($path, $updated);

        return $path;
    }

    /**
     * @param  array<string, string>  $profilesByHandle
     */
    private function writeFacetsSectionHandles(string $themeRoot, array $profilesByHandle): string
    {
        return $this->writeSingleInlineHandleCsv(
            $themeRoot.'/snippets/facets.liquid',
            $profilesByHandle,
            'facets.liquid',
        );
    }

    /**
     * @param  array<string, string>  $profilesByHandle
     */
    private function writeCollectionNavHandles(string $themeRoot, array $profilesByHandle): string
    {
        return $this->writeSingleInlineHandleCsv(
            $themeRoot.'/snippets/ovs-model-kit-collection-nav.liquid',
            $profilesByHandle,
            'ovs-model-kit-collection-nav.liquid',
        );
    }

    /**
     * @param  array<string, string>  $profilesByHandle
     */
    private function writeSingleInlineHandleCsv(string $path, array $profilesByHandle, string $label): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read {$label}: {$path}");
        }

        $handlesCsv = implode(',', array_keys($profilesByHandle));
        $block = "{%- comment -%} Generated by model-kit filter manifest generator — inline in parent (render scope). {%- endcomment -%}\n"
            ."{%- assign ovs_mk_filter_handles_csv = '{$handlesCsv}' -%}";

        $pattern = '/\{%- comment -%\} Generated by model-kit filter manifest generator — inline in parent \(render scope\)\. \{%- endcomment -%\}\s*\{%- assign ovs_mk_filter_handles_csv = \'[^\']*\' -%\}/';
        $updated = preg_replace($pattern, $block, $contents, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException("Could not locate MK filter handle block in {$label}");
        }

        file_put_contents($path, $updated);

        return $path;
    }

    /**
     * @param  array<string, string>  $profilesByHandle
     */
    private function writeProfileLiquid(string $themeRoot, array $profilesByHandle): string
    {
        $path = $themeRoot.'/snippets/ovs-model-kit-filter-profile.liquid';
        $profileCases = '';
        foreach ($profilesByHandle as $handle => $profile) {
            $profileCases .= "  {%- when '{$handle}' -%}\n    {%- assign ovs_mk_filter_profile = '{$profile}' -%}\n";
        }

        $profileBlock = <<<LIQUID
{%- assign ovs_mk_filter_profile = '' -%}
{%- case collection.handle -%}
{$profileCases}  {%- else -%}
    {%- assign ovs_mk_filter_profile = '' -%}
{%- endcase -%}

LIQUID;

        file_put_contents($path, "{%- comment -%} Generated by model-kit filter manifest generator — do not edit by hand. {%- endcomment -%}\n".$profileBlock);

        $this->writeCollectionFiltersProfileBlock($themeRoot, $profileBlock);

        return $path;
    }

    private function writeCollectionFiltersProfileBlock(string $themeRoot, string $profileBlock): void
    {
        $path = $themeRoot.'/snippets/ovs-model-kit-collection-filters.liquid';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read collection filters snippet: {$path}");
        }

        $start = '{%- comment -%} MK_FILTER_PROFILE_START {%- endcomment -%}';
        $end = '{%- comment -%} MK_FILTER_PROFILE_END {%- endcomment -%}';
        $replacement = $start."\n".$profileBlock.$end;

        if (! preg_match('/'.preg_quote($start, '/').'[\s\S]*?'.preg_quote($end, '/').'/m', $contents)) {
            throw new RuntimeException('MK_FILTER_PROFILE markers missing in ovs-model-kit-collection-filters.liquid');
        }

        $updated = preg_replace('/'.preg_quote($start, '/').'[\s\S]*?'.preg_quote($end, '/').'/m', $replacement, $contents, 1);
        if (! is_string($updated)) {
            throw new RuntimeException('Failed to update MK filter profile block in ovs-model-kit-collection-filters.liquid');
        }

        file_put_contents($path, $updated);
    }

    /**
     * @param  array<string, string>  $profilesByHandle
     */
    private function writeHandlesJavascript(string $themeRoot, array $profilesByHandle): string
    {
        $path = $themeRoot.'/assets/ovs-model-kit-collection-filters-handles.js';
        $jsHandles = implode(",\n    ", array_map(
            static fn (string $handle): string => "'".addslashes($handle)."'",
            array_keys($profilesByHandle),
        ));
        $jsProfiles = implode(",\n    ", array_map(
            static fn (string $handle, string $profile): string => "'".addslashes($handle)."': '".addslashes($profile)."'",
            array_keys($profilesByHandle),
            array_values($profilesByHandle),
        ));
        $shelfDefaultsJson = json_encode(
            ModelKitCollectionFilterShelfDefaults::javascriptObject(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $gradeRoutesJson = json_encode(
            ModelKitCollectionFilterShelfDefaults::gradeKeyCollectionHandles(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $lineRoutesJson = json_encode(
            ModelKitCollectionFilterShelfDefaults::lineKeyCollectionHandles(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $gunplaPoolJson = json_encode(
            ModelKitCollectionFilterGunplaPool::handles(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $hubPrefiltersJson = json_encode(
            ModelKitCollectionFilterHubNavigation::prefiltersByHandle(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $shelfTitlesJson = json_encode(
            ModelKitCollectionFilterHubNavigation::shelfTitlesByHandle(),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $contents = <<<JS
/* Generated by model-kit filter manifest generator — do not edit by hand. */
window.OVS_MK_FILTER_HANDLES = new Set([
    {$jsHandles}
]);
window.OVS_MK_FILTER_PROFILES = {
    {$jsProfiles}
};
window.OVS_MK_SHELF_DEFAULTS = {$shelfDefaultsJson};
window.OVS_MK_GRADE_COLLECTIONS = {$gradeRoutesJson};
window.OVS_MK_LINE_COLLECTIONS = {$lineRoutesJson};
window.OVS_MK_GUNPLA_POOL_HANDLES = new Set({$gunplaPoolJson});
window.OVS_MK_GUNPLA_HUB_HANDLE = 'model-kits';
window.OVS_MK_HUB_PREFILTERS = {$hubPrefiltersJson};
window.OVS_MK_SHELF_TITLES = {$shelfTitlesJson};

(function () {
  if (!window.OVS_MK_HUB_ONLY) {
    return;
  }

  const hub = window.OVS_MK_GUNPLA_HUB_HANDLE || 'model-kits';
  const parts = window.location.pathname.split('/').filter(Boolean);
  const handle = parts[parts.length - 1] || '';
  if (handle === hub || handle === 'latest-arrivals' || handle === 'beginner-kits') {
    return;
  }

  const params = new URLSearchParams(window.location.search);

  if (handle === 'gunpla') {
    const legacyUrl = new URL('/collections/' + hub, window.location.origin);
    params.forEach((value, key) => {
      legacyUrl.searchParams.set(key, value);
    });
    window.location.replace(legacyUrl.pathname + legacyUrl.search);
    return;
  }

  const pref = window.OVS_MK_HUB_PREFILTERS?.[handle];
  if (!pref || typeof pref !== 'object') {
    return;
  }

  if (
    params.has('ovs_mk_grade') ||
    params.has('ovs_mk_series') ||
    params.has('ovs_mk_franchise') ||
    params.has('ovs_mk_line') ||
    params.has('ovs_mk_price') ||
    params.has('ovs_mk_latest_arrival')
  ) {
    return;
  }

  const url = new URL('/collections/' + hub, window.location.origin);
  const preview = params.get('preview_theme_id');
  if (preview) {
    url.searchParams.set('preview_theme_id', preview);
  }
  if (Array.isArray(pref.grades) && pref.grades.length > 0) {
    url.searchParams.set('ovs_mk_grade', pref.grades.join(','));
  }
  if (Array.isArray(pref.series) && pref.series.length > 0) {
    url.searchParams.set('ovs_mk_series', pref.series.join(','));
  }
  if (Array.isArray(pref.franchises) && pref.franchises.length > 0) {
    url.searchParams.set('ovs_mk_franchise', pref.franchises.join(','));
  }
  if (Array.isArray(pref.lines) && pref.lines.length > 0) {
    url.searchParams.set('ovs_mk_line', pref.lines.join(','));
  }

  window.location.replace(url.pathname + url.search);
})();

JS;
        file_put_contents($path, $contents);

        return $path;
    }

    private function writeMegaMenuHubUrls(string $themeRoot): string
    {
        $path = $themeRoot.'/snippets/ovs-model-kits-mega-menu-poc.liquid';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Could not read mega menu snippet: {$path}");
        }

        $start = '{%- comment -%} MK_HUB_URLS_START {%- endcomment -%}';
        $end = '{%- comment -%} MK_HUB_URLS_END {%- endcomment -%}';
        $block = ModelKitCollectionFilterHubNavigation::liquidUrlCaptureBlock();
        $replacement = $start."\n".$block."\n".$end;

        if (! preg_match('/'.preg_quote($start, '/').'[\s\S]*?'.preg_quote($end, '/').'/m', $content)) {
            throw new RuntimeException('MK_HUB_URLS markers missing in ovs-model-kits-mega-menu-poc.liquid');
        }

        $updated = preg_replace('/'.preg_quote($start, '/').'[\s\S]*?'.preg_quote($end, '/').'/m', $replacement, $content, 1);
        if (! is_string($updated)) {
            throw new RuntimeException('Failed to update mega menu hub URLs');
        }

        file_put_contents($path, $updated);

        return $path;
    }

    /**
     * @param  array<string, string>  $profilesByHandle
     * @param  array<string, list<string>>  $profileGroups
     * @param  array<string, string>  $groupLabels
     * @param  array<string, string>  $urlParams
     * @param  array<string, array{handle: string, title: string}>  $shelves
     */
    private function writeRequirementsDoc(
        array $profilesByHandle,
        array $profileGroups,
        array $groupLabels,
        array $urlParams,
        array $shelves,
    ): string {
        $docPath = base_path('docs/requirements/model-kit-collection-filters.md');
        $handleCount = count($profilesByHandle);

        $doc = "# Model kit collection filters\n\n";
        $doc .= "Client-side OVS filters on **{$handleCount}** ERP-provisioned model-kit smart collections (`ModelKitShelfCatalog`). ";
        $doc .= "**Availability** is always the **last** filter — native Shopify `filter.v.availability` only; OVS hides native price/tag facets on these shelves.\n\n";
        $doc .= "Regenerate manifests after catalog changes:\n\n";
        $doc .= "- **Maintenance UI:** `/maintenance` → **Regenerate model-kit filter manifest** (typically under 1 second)\n";
        $doc .= "- **CLI:** `php artisan storefront:model-kit-collection-filter-manifest-generate`\n\n";
        $doc .= "## Global rules\n\n";
        $doc .= "| Rule | Detail |\n| --- | --- |\n";
        $doc .= "| Filter order | Custom OVS groups (profile-specific) → **Price** (when present) → **Availability** (Shopify native, always last) |\n";
        $doc .= "| Pagination | 250 products/page on filtered shelves; hidden while any OVS param is active |\n";
        $doc .= "| URL params | Comma-separated multiselect: `ovs_mk_grade`, `ovs_mk_series`, `ovs_mk_franchise`, `ovs_mk_line`, `ovs_mk_price` |\n";
        $doc .= "| Product attrs | `data-ovs-mk-grade`, `data-ovs-mk-sublines`, `data-ovs-mk-series`, `data-ovs-mk-lines`, `data-ovs-price-cents` on grid items |\n";
        $doc .= "| Theme path | `OVS_SHOPIFY_THEME_PATH` in `.env`, or sibling `../ovs-shopify-theme` |\n\n";
        $doc .= "## Profile → filter groups\n\n";
        $doc .= "| Profile | Custom filter groups (before Availability) |\n| --- | --- |\n";
        foreach ($profileGroups as $profile => $groups) {
            $labels = array_map(static fn (string $group): string => $groupLabels[$group] ?? $group, $groups);
            $doc .= '| `'.$profile.'` | '.implode(' → ', $labels)." |\n";
        }
        $doc .= "\n## Per-collection matrix\n\n";
        $doc .= "| Handle | Title | Profile | Filter groups |\n| --- | --- | --- | --- |\n";
        foreach ($shelves as $meta) {
            $handle = $meta['handle'];
            $profile = $profilesByHandle[$handle];
            $groups = $profileGroups[$profile];
            $labels = array_map(
                static fn (string $group): string => ($groupLabels[$group] ?? $group).' (`'.($urlParams[$group] ?? '').'`)',
                $groups,
            );
            $doc .= '| `'.$handle.'` | '.str_replace('|', '\\|', $meta['title']).' | `'.$profile.'` | '.implode(', ', $labels)." |\n";
        }
        $doc .= "\n## Out of scope (not in `ModelKitShelfCatalog`)\n\n";
        $doc .= "- `beginner-kits` — price-rule collection, not `mk:*` shelf\n";
        $doc .= "- `latest-arrivals` — store-wide arrivals, not taxonomy shelf\n";

        file_put_contents($docPath, $doc);

        return $docPath;
    }
}
