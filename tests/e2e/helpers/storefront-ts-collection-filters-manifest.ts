import { existsSync, readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export type FilterToggleCase = {
    name: string;
    path: string;
    checkboxSelector: string;
    paramKey: string;
};

export type MobileSmokeCase = {
    handle: string;
    path: string;
    checkboxSelector: string;
    paramKey: string;
};

type ManifestToggleCase = {
    name: string;
    checkboxSelector: string;
    paramKey: string;
};

type ManifestCombinationStep = {
    checkboxSelector: string;
    paramKey: string;
};

type ManifestCombinationCase = {
    name: string;
    steps: ManifestCombinationStep[];
};

export type FilterCombinationCase = {
    name: string;
    path: string;
    steps: ManifestCombinationStep[];
};

type ManifestCollection = {
    handle: string;
    path: string;
    snippets: string[];
    toggleCases: ManifestToggleCase[];
    combinationCases?: ManifestCombinationCase[];
    mobileSmoke: {
        checkboxSelector: string;
        paramKey: string;
    };
};

type ManifestSkippedCollection = {
    handle: string;
    snippets?: string[];
    reason: string;
};

export type StorefrontTsCollectionFiltersManifest = {
    version: number;
    collectionsWithCheckboxFilters: ManifestCollection[];
    collectionsWithoutCheckboxFilters: ManifestSkippedCollection[];
};

const E2E_DIR = path.dirname(fileURLToPath(import.meta.url));

export function resolvePricingToolRoot(): string {
    return path.resolve(E2E_DIR, '../../..');
}

export function resolveOvsShopifyThemeRoot(): string {
    const fromEnv = process.env.OVS_SHOPIFY_THEME_PATH?.trim();
    if (fromEnv && existsSync(fromEnv)) {
        return path.resolve(fromEnv);
    }

    const sibling = path.resolve(resolvePricingToolRoot(), '../ovs-shopify-theme');
    if (existsSync(sibling)) {
        return sibling;
    }

    throw new Error(
        'Could not locate ovs-shopify-theme. Set OVS_SHOPIFY_THEME_PATH to the theme repo root.',
    );
}

export function manifestPath(themeRoot: string = resolveOvsShopifyThemeRoot()): string {
    return path.join(themeRoot, 'docs/storefront-ts-collection-filters.manifest.json');
}

export function loadStorefrontTsCollectionFiltersManifest(
    themeRoot: string = resolveOvsShopifyThemeRoot(),
): StorefrontTsCollectionFiltersManifest {
    const raw = readFileSync(manifestPath(themeRoot), 'utf8');
    return JSON.parse(raw) as StorefrontTsCollectionFiltersManifest;
}

export function flattenToggleCases(
    manifest: StorefrontTsCollectionFiltersManifest,
): FilterToggleCase[] {
    return manifest.collectionsWithCheckboxFilters.flatMap((collection) =>
        collection.toggleCases.map((toggleCase) => ({
            name: toggleCase.name,
            path: collection.path,
            checkboxSelector: toggleCase.checkboxSelector,
            paramKey: toggleCase.paramKey,
        })),
    );
}

export function mobileSmokeCases(
    manifest: StorefrontTsCollectionFiltersManifest,
): MobileSmokeCase[] {
    return manifest.collectionsWithCheckboxFilters.map((collection) => ({
        handle: collection.handle,
        path: collection.path,
        checkboxSelector: collection.mobileSmoke.checkboxSelector,
        paramKey: collection.mobileSmoke.paramKey,
    }));
}

export function flattenCombinationCases(
    manifest: StorefrontTsCollectionFiltersManifest,
): FilterCombinationCase[] {
    return manifest.collectionsWithCheckboxFilters.flatMap((collection) =>
        (collection.combinationCases ?? []).map((combinationCase) => ({
            name: combinationCase.name,
            path: collection.path,
            steps: combinationCase.steps,
        })),
    );
}

const SNIPPET_HANDLE_PATTERN = /collection\.handle\s*==\s*['"]([^'"]+)['"]/g;
const OVS_FILTER_SNIPPET_GLOB = /^ovs-.*-collection-filters(?:-.*)?\.liquid$/;

function listOvsFilterSnippetFiles(themeRoot: string): string[] {
    const snippetsDir = path.join(themeRoot, 'snippets');
    return readdirSync(snippetsDir)
        .filter((fileName) => OVS_FILTER_SNIPPET_GLOB.test(fileName))
        .sort();
}

function handlesFromSnippet(themeRoot: string, snippetFileName: string): string[] {
    const content = readFileSync(path.join(themeRoot, 'snippets', snippetFileName), 'utf8');
    const handles = new Set<string>();
    for (const match of content.matchAll(SNIPPET_HANDLE_PATTERN)) {
        const handle = match[1];
        if (handle) {
            handles.add(handle);
        }
    }

    return [...handles];
}

/**
 * Ensures every ovs-*-collection-filters snippet in the theme is registered in the manifest
 * and that manifest entries point at real snippet files.
 */
export function validateManifestAgainstTheme(themeRoot: string = resolveOvsShopifyThemeRoot()): void {
    const manifest = loadStorefrontTsCollectionFiltersManifest(themeRoot);
    const snippetFiles = listOvsFilterSnippetFiles(themeRoot);
    const errors: string[] = [];

    const manifestByHandle = new Map<string, ManifestCollection>();
    for (const collection of manifest.collectionsWithCheckboxFilters) {
        if (manifestByHandle.has(collection.handle)) {
            errors.push(`Duplicate manifest entry for handle "${collection.handle}".`);
        }
        manifestByHandle.set(collection.handle, collection);

        if (collection.toggleCases.length === 0) {
            errors.push(`Manifest handle "${collection.handle}" must include at least one toggleCase.`);
        }

        if (!collection.mobileSmoke?.checkboxSelector || !collection.mobileSmoke?.paramKey) {
            errors.push(`Manifest handle "${collection.handle}" must include mobileSmoke.`);
        }

        for (const snippet of collection.snippets) {
            if (!snippetFiles.includes(snippet)) {
                errors.push(
                    `Manifest handle "${collection.handle}" references missing snippet "${snippet}".`,
                );
            }
        }
    }

    const skippedByHandle = new Map(
        manifest.collectionsWithoutCheckboxFilters.map((entry) => [entry.handle, entry]),
    );

    const snippetToHandles = new Map<string, string[]>();
    for (const snippetFile of snippetFiles) {
        const handles = handlesFromSnippet(themeRoot, snippetFile);
        snippetToHandles.set(snippetFile, handles);

        for (const handle of handles) {
            const covered =
                manifestByHandle.has(handle) ||
                skippedByHandle.has(handle) ||
                [...manifestByHandle.values()].some((entry) => entry.snippets.includes(snippetFile));

            if (!covered) {
                errors.push(
                    `Snippet "${snippetFile}" targets handle "${handle}" but no manifest entry covers it.`,
                );
            }
        }
    }

    for (const collection of manifest.collectionsWithCheckboxFilters) {
        for (const snippet of collection.snippets) {
            const handles = snippetToHandles.get(snippet) ?? [];
            if (!handles.includes(collection.handle)) {
                errors.push(
                    `Manifest handle "${collection.handle}" lists "${snippet}" but snippet guard does not match.`,
                );
            }
        }
    }

    for (const skipped of manifest.collectionsWithoutCheckboxFilters) {
        if (skipped.snippets) {
            for (const snippet of skipped.snippets) {
                if (!snippetFiles.includes(snippet)) {
                    errors.push(
                        `Skipped handle "${skipped.handle}" references missing snippet "${snippet}".`,
                    );
                }
            }
        }
    }

    if (errors.length > 0) {
        throw new Error(
            `storefront-ts-collection-filters manifest is out of sync with theme snippets:\n- ${errors.join('\n- ')}`,
        );
    }
}
