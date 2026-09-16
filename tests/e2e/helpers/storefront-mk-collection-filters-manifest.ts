import fs from 'node:fs';

import path from 'node:path';

import { fileURLToPath } from 'node:url';



const __dirname = path.dirname(fileURLToPath(import.meta.url));



export type MkToggleCase = {

    name: string;

    checkboxSelector: string;

    paramKey: string;

    paramValue?: string;

    uncheck?: boolean;

};



export type MkCombinationCase = {

    name: string;

    prefilterPath?: string;

    steps: MkToggleCase[];

    expectRemainingParam?: string;

    expectMinVisibleProducts?: number;

    expectVisibleTextAny?: string[];

    expectAbsentTextAny?: string[];

    expectCountTextMin?: number;

    expectCountTextMax?: number;

};



export type MkCollectionCase = {

    handle: string;

    path: string;

    expectTitle: string;

    expectBreadcrumb: boolean;

    toggleCases?: MkToggleCase[];

    combinationCases?: MkCombinationCase[];

    prefilterStabilityCases?: MkPrefilterStabilityCase[];

    nestedExpandPersistCases?: MkNestedExpandPersistCase[];

    absentCheckboxSelectors?: string[];

    mobileSmoke?: {

        openDrawerSelector: string;

        inlineFiltersHiddenSelector: string;

        drawerFilterSelector: string;

        paramKey: string;

    };

};



export type MkMegaMenuPrefilterCase = {

    name: string;

    path: string;

    expectCheckboxSelector: string;

    expectTitleContains: string;

    expectMinVisibleProducts?: number;

    expectVisibleTextAny?: string[];

};



export type MkNestedExpandPersistCase = {
    name: string;
    path: string;
    groupSelector: string;
    childSelector: string;
    paramKey: string;
    paramValue: string;
};

export type MkPrefilterStabilityCase = {

    name: string;

    path: string;

    settleMs?: number;

    expectCheckboxSelector: string;

    expectMinVisibleProducts: number;

    expectExactVisibleProducts?: number;

    expectMinMatches?: number;

    expectMaxMatches?: number;

    expectVisibleTextAny?: string[];

    expectAbsentTextAny?: string[];

    expectCardTitleContains?: string;

    expectCardAbsentBadgeSelectors?: string[];

    expectOnPhotoBadgeSelector?: string;

    expectOnPhotoBadgeMin?: number;

    requireFullIndex?: boolean;

    requireIndexFromCache?: boolean;

};



export type MkFiltersManifest = {

    version: number;

    previewThemeId: string;

    hubPath: string;

    collections: MkCollectionCase[];

    megaMenuPrefilterCases?: MkMegaMenuPrefilterCase[];

    listingUniquenessCases?: Array<{

        name: string;

        path: string;

    }>;

    listingCtaCases?: Array<{

        name: string;

        path: string;

        gridSelector?: string;

        waitForMkFilters?: boolean;

        requireFullIndex?: boolean;

        allowEmpty?: boolean;

        expectMinVisibleProducts?: number;

        expectNoCtas?: boolean;

        expectNoVisibleText?: string[];

        expectCardCtas?: Array<{

            titleContains: string;

            cta: string;

        }>;

    }>;

    listingCtaPdpCases?: Array<{

        name: string;

        path: string;

        cta: string;

    }>;

    preorders: {

        defaultPath: string;

        filteredPath: string;

        brandOtherPath: string;

        closedPath: string;

        expectMinVisibleProducts: number;

        expectTitle: string;

        brandOtherExpectTitleOnce: string;

        expectClosedVisibleTextAny: string[];

    };

};



export function loadStorefrontMkCollectionFiltersManifest(): MkFiltersManifest {

    const manifestPath = path.resolve(

        __dirname,

        '../../../../ovs-shopify-theme/docs/storefront-mk-collection-filters.manifest.json',

    );

    const raw = fs.readFileSync(manifestPath, 'utf8');

    return JSON.parse(raw) as MkFiltersManifest;

}



export function flattenMkToggleCases(manifest: MkFiltersManifest): Array<MkToggleCase & { collectionPath: string; handle: string }> {

    return manifest.collections.flatMap((collection) =>

        (collection.toggleCases ?? []).map((toggle) => ({

            ...toggle,

            collectionPath: collection.path,

            handle: collection.handle,

        })),

    );

}



export function flattenMkCombinationCases(

    manifest: MkFiltersManifest,

): Array<MkCombinationCase & { collectionPath: string; handle: string }> {

    return manifest.collections.flatMap((collection) =>

        (collection.combinationCases ?? []).map((combo) => ({

            ...combo,

            collectionPath: collection.path,

            handle: collection.handle,

        })),

    );

}



export function flattenMkNestedExpandPersistCases(
    manifest: MkFiltersManifest,
): Array<MkNestedExpandPersistCase & { handle: string }> {
    return manifest.collections.flatMap((collection) =>
        (collection.nestedExpandPersistCases ?? []).map((expandCase) => ({
            ...expandCase,
            handle: collection.handle,
        })),
    );
}

export function flattenMkPrefilterStabilityCases(

    manifest: MkFiltersManifest,

): Array<MkPrefilterStabilityCase & { handle: string }> {

    return manifest.collections.flatMap((collection) =>

        (collection.prefilterStabilityCases ?? []).map((stabilityCase) => ({

            ...stabilityCase,

            handle: collection.handle,

        })),

    );

}


