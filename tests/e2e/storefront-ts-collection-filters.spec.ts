import { expect, test } from '@playwright/test';

import {

    flattenCombinationCases,

    flattenPurityCases,

    flattenToggleCases,

    loadStorefrontTsCollectionFiltersManifest,

    mobileSmokeCases,

    type FilterCombinationCase,

    type FilterPurityCase,

    type FilterToggleCase,

    validateManifestAgainstTheme,

} from './helpers/storefront-ts-collection-filters-manifest';

import { assertVisibleListingCardsHaveCtas, flattenTsListingCtaCases } from './helpers/storefront-listing-cta';
import { assertUniqueVisibleProductHandles } from './helpers/storefront-unique-product-handles';



const STOREFRONT_BASE_URL =

    process.env.STOREFRONT_BASE_URL ?? process.env.E2E_STOREFRONT_URL ?? 'https://operationvstudio.com';



const manifest = loadStorefrontTsCollectionFiltersManifest();

const FILTER_TOGGLE_CASES = flattenToggleCases(manifest);

const FILTER_COMBINATION_CASES = flattenCombinationCases(manifest);

const FILTER_PURITY_CASES = flattenPurityCases(manifest);

const MOBILE_SMOKE_CASES = mobileSmokeCases(manifest);



async function visibleGridCount(page: import('@playwright/test').Page): Promise<number> {

    return page.locator('#product-grid .grid__item:not([hidden])').count();

}



async function setOvsCheckbox(

    checkbox: import('@playwright/test').Locator,

    checked: boolean,

): Promise<void> {

    await checkbox.evaluate((element, shouldCheck) => {

        const input = element as HTMLInputElement;

        if (input.checked === shouldCheck) {

            return;

        }



        input.checked = shouldCheck;

        input.dispatchEvent(new Event('change', { bubbles: true }));

    }, checked);

}



async function assertFilterCheckThenUncheck(

    page: import('@playwright/test').Page,

    filterCase: FilterToggleCase,

): Promise<void> {

    const url = `${STOREFRONT_BASE_URL}${filterCase.path}`;

    await page.goto(url, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#product-grid')).toBeVisible();



    const desktopCheckbox = page

        .locator('#main-collection-filters')

        .locator(filterCase.checkboxSelector)

        .first();

    await expect(desktopCheckbox).toBeVisible();



    const mobileCheckbox = page.locator('#FacetsWrapperMobile').locator(filterCase.checkboxSelector).first();



    const baselineCount = await visibleGridCount(page);



    await setOvsCheckbox(desktopCheckbox, true);

    await expect(desktopCheckbox).toBeChecked();

    await expect(mobileCheckbox).toBeChecked();

    await expect

        .poll(() => new URL(page.url()).searchParams.get(filterCase.paramKey))

        .not.toBeNull();



    const filteredCount = await visibleGridCount(page);

    expect(filteredCount).toBeLessThanOrEqual(baselineCount);

    await assertUniqueVisibleProductHandles(page);



    await setOvsCheckbox(desktopCheckbox, false);

    await expect(desktopCheckbox).not.toBeChecked();

    await expect(mobileCheckbox).not.toBeChecked();

    await expect

        .poll(() => new URL(page.url()).searchParams.get(filterCase.paramKey))

        .toBeNull();



    const afterUncheckCount = await visibleGridCount(page);

    if (filteredCount < baselineCount) {

        expect(afterUncheckCount).toBeGreaterThan(filteredCount);

    } else {

        expect(afterUncheckCount).toBeGreaterThanOrEqual(filteredCount);

    }

    await assertUniqueVisibleProductHandles(page);

}



async function assertFilterCombination(

    page: import('@playwright/test').Page,

    combinationCase: FilterCombinationCase,

): Promise<void> {

    const url = `${STOREFRONT_BASE_URL}${combinationCase.path}`;

    await page.goto(url, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#product-grid')).toBeVisible();



    const baselineCount = await visibleGridCount(page);

    expect(baselineCount).toBeGreaterThan(0);



    let previousCount = baselineCount;



    for (const step of combinationCase.steps) {

        const desktopCheckbox = page

            .locator('#main-collection-filters')

            .locator(step.checkboxSelector)

            .first();

        await expect(desktopCheckbox).toBeVisible();

        await setOvsCheckbox(desktopCheckbox, true);

        await expect(desktopCheckbox).toBeChecked();

        await expect

            .poll(() => new URL(page.url()).searchParams.get(step.paramKey))

            .not.toBeNull();

    }



    const combinedCount = await visibleGridCount(page);

    expect(combinedCount).toBeLessThanOrEqual(previousCount);

    await assertUniqueVisibleProductHandles(page);



    for (const step of [...combinationCase.steps].reverse()) {

        const desktopCheckbox = page

            .locator('#main-collection-filters')

            .locator(step.checkboxSelector)

            .first();

        await setOvsCheckbox(desktopCheckbox, false);

        await expect(desktopCheckbox).not.toBeChecked();

        await expect.poll(() => new URL(page.url()).searchParams.get(step.paramKey)).toBeNull();

        previousCount = await visibleGridCount(page);

    }



    const afterClearCount = await visibleGridCount(page);

    expect(afterClearCount).toBeGreaterThanOrEqual(combinedCount);

}



test.describe('Storefront T&S collection filters — manifest registry', () => {

    test('manifest covers every ovs-*-collection-filters snippet', () => {

        validateManifestAgainstTheme();

    });

});



test.describe('Storefront T&S collection filters — check then uncheck', () => {

    test.describe.configure({ mode: 'serial' });



    for (const filterCase of FILTER_TOGGLE_CASES) {

        test(filterCase.name, async ({ page }) => {

            await page.setViewportSize({ width: 1280, height: 900 });

            await assertFilterCheckThenUncheck(page, filterCase);

        });

    }

});

async function visibleTitles(page: import('@playwright/test').Page): Promise<string[]> {
    const titles = await page
        .locator('#product-grid .grid__item:not([hidden]):not(.hidden) .card__heading')
        .allTextContents();
    return titles.map((title) => title.replace(/\s+/g, ' ').trim()).filter((title) => title !== '');
}

async function assertFilterPurity(
    page: import('@playwright/test').Page,
    purityCase: FilterPurityCase,
): Promise<void> {
    await page.goto(`${STOREFRONT_BASE_URL}${purityCase.path}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#product-grid')).toBeVisible();

    for (const step of purityCase.steps) {
        const checkbox = page.locator('#main-collection-filters').locator(step.checkboxSelector).first();
        await expect(checkbox).toBeVisible();
        const label = page
            .locator('#main-collection-filters label')
            .filter({ has: page.locator(step.checkboxSelector) })
            .first();
        await label.click();
        if (step.check) {
            await expect(checkbox).toBeChecked();
        } else {
            await expect(checkbox).not.toBeChecked();
        }
        await page.waitForTimeout(400);
    }

    await assertUniqueVisibleProductHandles(page);

    const uniqueTitles = [...new Set(await visibleTitles(page))];

    if (purityCase.expectExactVisibleProducts !== undefined) {
        expect(await visibleGridCount(page)).toBe(purityCase.expectExactVisibleProducts);
    }

    if ((purityCase.expectVisibleTextAny ?? []).length > 0) {
        const needles = purityCase.expectVisibleTextAny ?? [];
        expect(uniqueTitles.some((title) => needles.some((needle) => title.includes(needle)))).toBe(true);
    }

    for (const absent of purityCase.expectAbsentVisibleText ?? []) {
        expect(uniqueTitles.some((title) => title.includes(absent))).toBe(false);
    }
}

test.describe('Storefront T&S collection filters — row purity', () => {
    test.describe.configure({ mode: 'serial' });

    for (const purityCase of FILTER_PURITY_CASES) {
        test(purityCase.name, async ({ page }) => {
            await page.setViewportSize({ width: 1400, height: 1100 });
            await assertFilterPurity(page, purityCase);
        });
    }
});

test.describe('Storefront T&S collection filters — multigroup combinations', () => {

    test.describe.configure({ mode: 'serial' });



    for (const combinationCase of FILTER_COMBINATION_CASES) {

        test(combinationCase.name, async ({ page }) => {

            await page.setViewportSize({ width: 1280, height: 900 });

            await assertFilterCombination(page, combinationCase);

        });

    }

});



test.describe('Storefront T&S collection filters — mobile drawer mirrors desktop', () => {

    test.describe.configure({ mode: 'serial' });



    for (const mobileCase of MOBILE_SMOKE_CASES) {

        test(`${mobileCase.handle} uncheck in mobile drawer widens grid`, async ({ page }) => {

            await page.setViewportSize({ width: 390, height: 844 });

            await page.goto(`${STOREFRONT_BASE_URL}${mobileCase.path}`, { waitUntil: 'domcontentloaded' });



            await page.locator('.mobile-facets__open').click();

            await expect(page.locator('#FacetsWrapperMobile')).toBeVisible();



            const mobileCheckbox = page

                .locator(`#FacetsWrapperMobile ${mobileCase.checkboxSelector}`)

                .first();



            await setOvsCheckbox(mobileCheckbox, true);

            await expect(mobileCheckbox).toBeChecked();



            const filteredCount = await visibleGridCount(page);



            await setOvsCheckbox(mobileCheckbox, false);

            await expect(mobileCheckbox).not.toBeChecked();

            await expect

                .poll(() => new URL(page.url()).searchParams.get(mobileCase.paramKey))

                .toBeNull();



            const afterUncheckCount = await visibleGridCount(page);

            expect(afterUncheckCount).toBeGreaterThan(filteredCount);

            await assertUniqueVisibleProductHandles(page);

        });

    }

});

test.describe('Storefront T&S listing uniqueness — one handle per card', () => {
    test.describe.configure({ mode: 'serial' });

    for (const listingCase of manifest.listingUniquenessCases ?? []) {
        test(listingCase.name, async ({ page }) => {
            await page.setViewportSize({ width: 1280, height: 900 });
            await page.goto(`${STOREFRONT_BASE_URL}${listingCase.path}`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('#product-grid')).toBeVisible();
            await assertUniqueVisibleProductHandles(page);
        });
    }
});

test.describe('Storefront listing CTAs — every T&S collection', () => {
    test.setTimeout(60_000);

    for (const listingCase of flattenTsListingCtaCases()) {
        test(listingCase.name, async ({ page }) => {
            await page.setViewportSize({ width: 1400, height: 900 });
            await page.goto(`${STOREFRONT_BASE_URL}${listingCase.path}`, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('#product-grid, ul.product-grid').first()).toBeVisible({ timeout: 20000 });
            await page.waitForTimeout(1500);
            await assertVisibleListingCardsHaveCtas(page, listingCase);
        });
    }
});


