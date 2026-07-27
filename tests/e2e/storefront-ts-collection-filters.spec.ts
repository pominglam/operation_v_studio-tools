import { expect, test } from '@playwright/test';

import {

    flattenCombinationCases,

    flattenToggleCases,

    loadStorefrontTsCollectionFiltersManifest,

    mobileSmokeCases,

    type FilterCombinationCase,

    type FilterToggleCase,

    validateManifestAgainstTheme,

} from './helpers/storefront-ts-collection-filters-manifest';



const STOREFRONT_BASE_URL =

    process.env.STOREFRONT_BASE_URL ?? process.env.E2E_STOREFRONT_URL ?? 'https://operationvstudio.com';



const manifest = loadStorefrontTsCollectionFiltersManifest();

const FILTER_TOGGLE_CASES = flattenToggleCases(manifest);

const FILTER_COMBINATION_CASES = flattenCombinationCases(manifest);

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

        });

    }

});


