<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

final class ShopifyStorefrontCollectionFiltersManifestVerifyCommand extends Command
{
    private const SNIPPET_HANDLE_PATTERN = '/collection\.handle\s*==\s*[\'"]([^\'"]+)[\'"]/';

    private const OVS_FILTER_SNIPPET_PATTERN = '/^ovs-.*-collection-filters(?:-.*)?\.liquid$/';

    protected $signature = 'shopify:storefront-collection-filters-manifest-verify
                            {--theme-path= : Path to ovs-shopify-theme root (default: sibling ../ovs-shopify-theme)}';

    protected $description = 'Verify storefront-ts-collection-filters.manifest.json covers every ovs-*-collection-filters snippet.';

    public function handle(): int
    {
        $themeRoot = $this->resolveThemeRoot();
        $manifestPath = $themeRoot.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'storefront-ts-collection-filters.manifest.json';

        if (! is_file($manifestPath)) {
            $this->error("Manifest not found: {$manifestPath}");

            return self::FAILURE;
        }

        /** @var array{
         *     collectionsWithCheckboxFilters: list<array{
         *         handle: string,
         *         snippets: list<string>,
         *         toggleCases: list<array<string, mixed>>,
         *         mobileSmoke?: array{checkboxSelector?: string, paramKey?: string}
         *     }>,
         *     collectionsWithoutCheckboxFilters: list<array{
         *         handle: string,
         *         snippets?: list<string>,
         *         reason?: string
         *     }>
         * } $manifest
         */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $snippetsDir = $themeRoot.DIRECTORY_SEPARATOR.'snippets';
        if (! is_dir($snippetsDir)) {
            $this->error("Snippets directory not found: {$snippetsDir}");

            return self::FAILURE;
        }

        $snippetFiles = array_values(array_filter(
            scandir($snippetsDir) ?: [],
            static fn (string $file): bool => (bool) preg_match(self::OVS_FILTER_SNIPPET_PATTERN, $file),
        ));
        sort($snippetFiles);

        $errors = [];
        $manifestByHandle = [];

        foreach ($manifest['collectionsWithCheckboxFilters'] as $collection) {
            $handle = $collection['handle'];
            if (isset($manifestByHandle[$handle])) {
                $errors[] = "Duplicate manifest entry for handle \"{$handle}\".";
            }
            $manifestByHandle[$handle] = $collection;

            if ($collection['toggleCases'] === []) {
                $errors[] = "Manifest handle \"{$handle}\" must include at least one toggleCase.";
            }

            $mobileSmoke = $collection['mobileSmoke'] ?? [];
            if (($mobileSmoke['checkboxSelector'] ?? '') === '' || ($mobileSmoke['paramKey'] ?? '') === '') {
                $errors[] = "Manifest handle \"{$handle}\" must include mobileSmoke.";
            }

            foreach ($collection['snippets'] as $snippet) {
                if (! in_array($snippet, $snippetFiles, true)) {
                    $errors[] = "Manifest handle \"{$handle}\" references missing snippet \"{$snippet}\".";
                }
            }
        }

        $skippedByHandle = [];
        foreach ($manifest['collectionsWithoutCheckboxFilters'] as $skipped) {
            $skippedByHandle[$skipped['handle']] = $skipped;
            foreach ($skipped['snippets'] ?? [] as $snippet) {
                if (! in_array($snippet, $snippetFiles, true)) {
                    $errors[] = "Skipped handle \"{$skipped['handle']}\" references missing snippet \"{$snippet}\".";
                }
            }
        }

        $snippetToHandles = [];
        foreach ($snippetFiles as $snippetFile) {
            $content = (string) file_get_contents($snippetsDir.DIRECTORY_SEPARATOR.$snippetFile);
            preg_match_all(self::SNIPPET_HANDLE_PATTERN, $content, $matches);
            $handles = array_values(array_unique($matches[1] ?? []));
            $snippetToHandles[$snippetFile] = $handles;

            foreach ($handles as $handle) {
                $covered = isset($manifestByHandle[$handle])
                    || isset($skippedByHandle[$handle])
                    || $this->snippetListedInManifest($manifest, $snippetFile);

                if (! $covered) {
                    $errors[] = "Snippet \"{$snippetFile}\" targets handle \"{$handle}\" but no manifest entry covers it.";
                }
            }
        }

        foreach ($manifest['collectionsWithCheckboxFilters'] as $collection) {
            foreach ($collection['snippets'] as $snippet) {
                $handles = $snippetToHandles[$snippet] ?? [];
                if (! in_array($collection['handle'], $handles, true)) {
                    $errors[] = "Manifest handle \"{$collection['handle']}\" lists \"{$snippet}\" but snippet guard does not match.";
                }
            }
        }

        if ($errors !== []) {
            $this->error('Manifest is out of sync with theme snippets:');
            foreach ($errors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        $toggleCount = array_sum(array_map(
            static fn (array $collection): int => count($collection['toggleCases']),
            $manifest['collectionsWithCheckboxFilters'],
        ));

        $this->info(sprintf(
            'Manifest OK: %d checkbox-filter collections, %d toggle cases, %d snippet files scanned.',
            count($manifest['collectionsWithCheckboxFilters']),
            $toggleCount,
            count($snippetFiles),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     collectionsWithCheckboxFilters: list<array{snippets: list<string>}>,
     *     collectionsWithoutCheckboxFilters: list<array{snippets?: list<string>}>
     * }  $manifest
     */
    private function snippetListedInManifest(array $manifest, string $snippetFile): bool
    {
        foreach ($manifest['collectionsWithCheckboxFilters'] as $collection) {
            if (in_array($snippetFile, $collection['snippets'], true)) {
                return true;
            }
        }

        foreach ($manifest['collectionsWithoutCheckboxFilters'] as $skipped) {
            if (in_array($snippetFile, $skipped['snippets'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveThemeRoot(): string
    {
        $fromOption = $this->option('theme-path');
        if (is_string($fromOption) && $fromOption !== '') {
            $resolved = realpath($fromOption);
            if ($resolved === false) {
                throw new RuntimeException("Theme path not found: {$fromOption}");
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
                'Could not locate ovs-shopify-theme. Pass --theme-path or set OVS_SHOPIFY_THEME_PATH.',
            );
        }

        return $sibling;
    }
}
