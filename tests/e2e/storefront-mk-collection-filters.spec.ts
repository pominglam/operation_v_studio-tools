import { expect, test } from '@playwright/test';



import {

    flattenMkCombinationCases,

    flattenMkNestedExpandPersistCases,

    flattenMkPrefilterStabilityCases,

    flattenMkToggleCases,

    loadStorefrontMkCollectionFiltersManifest,

    type MkCombinationCase,

    type MkNestedExpandPersistCase,

    type MkPrefilterStabilityCase,

    type MkToggleCase,

} from './helpers/storefront-mk-collection-filters-manifest';

import {
    assertPdpHasListingCta,
    assertVisibleListingCardsHaveCtas,
    flattenMkListingCtaCases,
} from './helpers/storefront-listing-cta';
import { assertUniqueVisibleProductHandles } from './helpers/storefront-unique-product-handles';



const STOREFRONT_BASE_URL =

    process.env.STOREFRONT_BASE_URL ?? process.env.E2E_STOREFRONT_URL ?? 'https://operationvstudio.com';



const manifest = loadStorefrontMkCollectionFiltersManifest();

const FILTER_TOGGLE_CASES = flattenMkToggleCases(manifest);

const FILTER_COMBINATION_CASES = flattenMkCombinationCases(manifest);

const FILTER_PREFILTER_STABILITY_CASES = flattenMkPrefilterStabilityCases(manifest);

const FILTER_NESTED_EXPAND_PERSIST_CASES = flattenMkNestedExpandPersistCases(manifest);



type MkFiltersReadyOptions = {

    requireFullIndex?: boolean;

    requireIndexFromCache?: boolean;

};



async function waitForMkFiltersReady(

    page: import('@playwright/test').Page,

    options: MkFiltersReadyOptions = {},

): Promise<void> {

    await page.waitForFunction(
        () => {
            const win = window as Window & { OVS_MK_FILTERS_READY?: boolean };

            return win.OVS_MK_FILTERS_READY === true;
        },
        { timeout: 45000 },
    );

    if (options.requireFullIndex) {
        await page.waitForFunction(
            () => {
                const win = window as Window & {
                    OVS_MK_INDEX_COMPLETE?: boolean;
                    OVS_MK_INDEX_ROW_COUNT?: number;
                };

                return win.OVS_MK_INDEX_COMPLETE === true && (win.OVS_MK_INDEX_ROW_COUNT ?? 0) >= 700;
            },
            { timeout: 45000 },
        );
    }

    if (options.requireIndexFromCache) {
        await page.waitForFunction(
            () => {
                const win = window as Window & {
                    OVS_MK_INDEX_FROM_CACHE?: boolean;
                    OVS_MK_INDEX_COMPLETE?: boolean;
                    OVS_MK_INDEX_ROW_COUNT?: number;
                };

                return (
                    win.OVS_MK_INDEX_FROM_CACHE === true &&
                    win.OVS_MK_INDEX_COMPLETE === true &&
                    (win.OVS_MK_INDEX_ROW_COUNT ?? 0) >= 700
                );
            },
            { timeout: 8000 },
        );
    }

}



async function readMkBrowseMatches(page: import('@playwright/test').Page): Promise<number> {

    return page.evaluate(() => {
        const win = window as Window & { OVS_MK_LAST_BROWSE?: { matches?: number } };

        return win.OVS_MK_LAST_BROWSE?.matches ?? 0;
    });

}



async function visibleGridCount(page: import('@playwright/test').Page): Promise<number> {

    return page.locator('#product-grid .grid__item:not([hidden])').count();

}



const PRODUCT_COUNT_SELECTOR = '#ProductCountDesktop, #ProductCount, .product-count__text span';



async function readProductCount(page: import('@playwright/test').Page): Promise<number> {

    const text = await page.locator(PRODUCT_COUNT_SELECTOR).first().innerText();

    return Number(text.match(/(\d+)/)?.[1] ?? '0');

}



async function setOvsCheckbox(checkbox: import('@playwright/test').Locator, checked: boolean): Promise<void> {

    await checkbox.evaluate((element, shouldCheck) => {

        const input = element as HTMLInputElement;

        if (input.checked === shouldCheck) {

            return;

        }

        input.checked = shouldCheck;

        input.dispatchEvent(new Event('change', { bubbles: true }));

    }, checked);

}



function gradeParam(url: string): string | null {

    return new URL(url).searchParams.get('ovs_mk_grade');

}



async function assertFilterCheckThenUncheck(

    page: import('@playwright/test').Page,

    collectionPath: string,

    filterCase: MkToggleCase,

): Promise<void> {

    await page.goto(`${STOREFRONT_BASE_URL}${collectionPath}`, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#product-grid')).toBeVisible();

    await waitForMkFiltersReady(page);



    const desktopCheckbox = page.locator('#main-collection-filters').locator(filterCase.checkboxSelector).first();

    await expect(desktopCheckbox).toBeVisible({ timeout: 15000 });



    const mobileCheckbox = page.locator('#FacetsWrapperMobile').locator(filterCase.checkboxSelector).first();



    const pathBefore = new URL(page.url()).pathname;

    const baselineCount = await visibleGridCount(page);



    await setOvsCheckbox(desktopCheckbox, true);

    await expect(desktopCheckbox).toBeChecked();

    await expect(mobileCheckbox).toBeChecked();

    expect(new URL(page.url()).pathname).toBe(pathBefore);



    await expect.poll(() => new URL(page.url()).searchParams.get(filterCase.paramKey)).not.toBeNull();



    if (filterCase.paramValue && filterCase.paramKey === 'ovs_mk_grade') {

        await expect.poll(() => gradeParam(page.url())).toContain(filterCase.paramValue);

    }



    const filteredCount = await visibleGridCount(page);

    expect(filteredCount).toBeLessThanOrEqual(baselineCount);

    await assertUniqueVisibleProductHandles(page);



    await setOvsCheckbox(desktopCheckbox, false);

    await expect(desktopCheckbox).not.toBeChecked();

    await expect(mobileCheckbox).not.toBeChecked();



    if (filterCase.paramKey === 'ovs_mk_grade' && filterCase.paramValue) {

        await expect
            .poll(() => {
                const param = gradeParam(page.url());
                return param === null || !param.includes(filterCase.paramValue!);
            })
            .toBe(true);

    } else {

        await expect.poll(() => new URL(page.url()).searchParams.get(filterCase.paramKey)).toBeNull();

    }



    const afterUncheckCount = await visibleGridCount(page);

    if (filteredCount < baselineCount) {

        expect(afterUncheckCount).toBeGreaterThan(filteredCount);

    }

    await assertUniqueVisibleProductHandles(page);

}



async function assertFilterCombination(

    page: import('@playwright/test').Page,

    combo: MkCombinationCase & { collectionPath: string },

): Promise<void> {

    const startPath = combo.prefilterPath ?? combo.collectionPath;

    await page.goto(`${STOREFRONT_BASE_URL}${startPath}`, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#product-grid')).toBeVisible();

    const needsFullIndex = combo.expectCountTextMin !== undefined || combo.expectCountTextMax !== undefined;

    await waitForMkFiltersReady(page, { requireFullIndex: needsFullIndex });



    const baselineCount = await visibleGridCount(page);

    expect(baselineCount).toBeGreaterThan(0);



    for (const step of combo.steps) {

        const desktopCheckbox = page.locator('#main-collection-filters').locator(step.checkboxSelector).first();

        await expect(desktopCheckbox).toBeVisible();

        const mobileCheckbox = page.locator('#FacetsWrapperMobile').locator(step.checkboxSelector).first();



        if (step.uncheck) {

            await expect(desktopCheckbox).toBeChecked();

            await setOvsCheckbox(desktopCheckbox, false);

            await expect(desktopCheckbox).not.toBeChecked();

            await expect(mobileCheckbox).not.toBeChecked();



            if (step.paramValue && step.paramKey === 'ovs_mk_grade') {

                await expect.poll(() => gradeParam(page.url())).not.toContain(step.paramValue);

            }



            if (combo.expectRemainingParam) {

                await expect.poll(() => gradeParam(page.url())).toContain(combo.expectRemainingParam);

            }

        } else {

            await setOvsCheckbox(desktopCheckbox, true);

            await expect(desktopCheckbox).toBeChecked();

            await expect(mobileCheckbox).toBeChecked();

            await expect.poll(() => new URL(page.url()).searchParams.get(step.paramKey)).not.toBeNull();

        }

    }



    if (!combo.steps.some((step) => step.uncheck)) {

        if (combo.expectCountTextMin !== undefined) {

            await expect.poll(async () => readProductCount(page), { timeout: 45000 }).toBeGreaterThanOrEqual(

                combo.expectCountTextMin,

            );

        }

        if (combo.expectCountTextMax !== undefined) {

            await expect.poll(async () => readProductCount(page), { timeout: 45000 }).toBeLessThanOrEqual(

                combo.expectCountTextMax,

            );

        }

        if (combo.expectMinVisibleProducts !== undefined) {

            await expect.poll(async () => visibleGridCount(page), { timeout: 45000 }).toBeGreaterThanOrEqual(

                combo.expectMinVisibleProducts,

            );

        }

        const combinedCount = await visibleGridCount(page);

        expect(combinedCount).toBeLessThanOrEqual(baselineCount);

        await assertUniqueVisibleProductHandles(page);

        if (combo.expectVisibleTextAny !== undefined && combo.expectVisibleTextAny.length > 0) {

            await expect

                .poll(async () => {

                    const visibleText = await page.locator('#product-grid .grid__item:not([hidden])').allInnerTexts();

                    return visibleText.join('\n');

                }, { timeout: 45000 })

                .toMatch(

                    new RegExp(

                        combo.expectVisibleTextAny.map((needle) => needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'),

                        'i',

                    ),

                );

        }

        if (combo.expectAbsentTextAny !== undefined && combo.expectAbsentTextAny.length > 0) {

            const visibleText = await page.locator('#product-grid .grid__item:not([hidden])').allInnerTexts();

            const haystack = visibleText.join('\n');

            for (const needle of combo.expectAbsentTextAny) {

                expect(haystack, `visible grid should not include ${needle}`).not.toMatch(

                    new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'),

                );

            }

        }

    }

}



test.describe('Storefront MK collection filters — check then uncheck', () => {

    for (const filterCase of FILTER_TOGGLE_CASES) {

        test(`${filterCase.handle}: ${filterCase.name} toggles without full reload`, async ({ page }) => {

            await assertFilterCheckThenUncheck(page, filterCase.collectionPath, filterCase);

        });

    }

});



test.describe('Storefront MK collection filters — combinations', () => {

    test.setTimeout(120_000);

    for (const combo of FILTER_COMBINATION_CASES) {

        test(`${combo.handle}: ${combo.name}`, async ({ page }) => {

            await assertFilterCombination(page, combo);

        });

    }

});

async function assertNestedExpandPersist(
    page: import('@playwright/test').Page,
    expandCase: MkNestedExpandPersistCase & { handle: string },
): Promise<void> {
    await page.goto(`${STOREFRONT_BASE_URL}${expandCase.path}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#product-grid')).toBeVisible();
    await page.waitForFunction(
        () => document.querySelectorAll('#main-collection-filters .ovs-mk-filters__toggle').length > 0,
        { timeout: 45_000 },
    );

    const group = page.locator('#main-collection-filters').locator(expandCase.groupSelector).first();
    const children = group.locator('.ovs-mk-filters__children').first();
    const toggle = group.locator('.ovs-mk-filters__toggle');
    const child = group.locator(expandCase.childSelector).first();

    await expect(toggle).toBeVisible();
    const alreadyExpanded = (await toggle.getAttribute('aria-expanded')) === 'true';
    if (!alreadyExpanded) {
        await toggle.click();
    }
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(children).not.toHaveClass(/ovs-mk-filters__children--collapsed/);
    await expect(child).toBeVisible();

    await setOvsCheckbox(child, true);
    await expect(child).toBeChecked();
    await expect.poll(() => new URL(page.url()).searchParams.get(expandCase.paramKey)).toContain(expandCase.paramValue);

    await setOvsCheckbox(child, false);
    await expect(child).not.toBeChecked();
    await expect
        .poll(() => {
            const param = new URL(page.url()).searchParams.get(expandCase.paramKey);
            return param === null || !param.includes(expandCase.paramValue);
        })
        .toBe(true);

    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(children).not.toHaveClass(/ovs-mk-filters__children--collapsed/);
    await expect(child).toBeVisible();
}

test.describe('Storefront MK collection filters — nested group stays expanded', () => {
    test.setTimeout(90_000);

    for (const expandCase of FILTER_NESTED_EXPAND_PERSIST_CASES) {
        test(`${expandCase.handle}: ${expandCase.name}`, async ({ page }) => {
            await assertNestedExpandPersist(page, expandCase);
        });
    }
});

async function assertBadgesOverlapPhoto(
    page: import('@playwright/test').Page,
    selector: string,
    minCount: number,
): Promise<void> {
    await expect
        .poll(
            async () => {
                return page.evaluate(
                    ({ badgeSelector, minOnPhoto }) => {
                        const items = Array.from(
                            document.querySelectorAll('#product-grid .grid__item'),
                        ).filter((item) => !(item as HTMLElement).hidden);
                        const badges = items.flatMap((item) =>
                            Array.from(item.querySelectorAll(badgeSelector)),
                        );
                        const onPhoto = badges.filter((badge) => {
                            const item = badge.closest('.grid__item');
                            const inner = item?.querySelector('.card__inner');
                            if (!(inner instanceof HTMLElement) || !(badge instanceof HTMLElement)) {
                                return false;
                            }
                            const badgeBox = badge.getBoundingClientRect();
                            const innerBox = inner.getBoundingClientRect();
                            const wrap = badge.closest('.card__badge');
                            const wrapStyle = window.getComputedStyle(wrap || badge);
                            const style = window.getComputedStyle(badge);
                            if (
                                badgeBox.width < 8 ||
                                badgeBox.height < 8 ||
                                wrapStyle.position !== 'absolute' ||
                                style.visibility === 'hidden' ||
                                style.display === 'none' ||
                                Number(style.opacity) === 0
                            ) {
                                return false;
                            }

                            // .ratio inner can grow below the square photo when a badge is in-flow.
                            const photoBottom = innerBox.top + innerBox.width;

                            return (
                                badgeBox.top < photoBottom - 4 &&
                                badgeBox.bottom > innerBox.top + 4 &&
                                badgeBox.right > innerBox.left + 4 &&
                                badgeBox.left < innerBox.right - 4
                            );
                        }).length;

                        return onPhoto >= minOnPhoto ? onPhoto : 0;
                    },
                    { badgeSelector: selector, minOnPhoto: minCount },
                );
            },
            { timeout: 45000 },
        )
        .toBeGreaterThanOrEqual(minCount);
}

async function assertPrefilterStability(

    page: import('@playwright/test').Page,

    stabilityCase: MkPrefilterStabilityCase & { handle: string },

): Promise<void> {

    await page.goto(`${STOREFRONT_BASE_URL}${stabilityCase.path}`, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#product-grid')).toBeVisible();

    await waitForMkFiltersReady(page, {
        requireFullIndex: stabilityCase.requireFullIndex ?? false,
        requireIndexFromCache: stabilityCase.requireIndexFromCache ?? false,
    });



    const desktopCheckbox = page

        .locator('#main-collection-filters')

        .locator(stabilityCase.expectCheckboxSelector)

        .first();

    await expect(desktopCheckbox).toBeChecked();



    const mobileCheckbox = page.locator('#FacetsWrapperMobile').locator(stabilityCase.expectCheckboxSelector).first();

    await expect(mobileCheckbox).toBeChecked();



    const afterReady = await visibleGridCount(page);

    expect(afterReady).toBeGreaterThanOrEqual(stabilityCase.expectMinVisibleProducts);



    if (stabilityCase.expectMinMatches !== undefined) {

        await expect.poll(async () => readMkBrowseMatches(page), { timeout: 45000 }).toBeGreaterThanOrEqual(

            stabilityCase.expectMinMatches,

        );

    }

    if (stabilityCase.expectMaxMatches !== undefined) {

        await expect.poll(async () => readMkBrowseMatches(page), { timeout: 45000 }).toBeLessThanOrEqual(

            stabilityCase.expectMaxMatches,

        );

    }



    await page.waitForTimeout(stabilityCase.settleMs ?? 2000);



    const afterSettle = await visibleGridCount(page);

    expect(afterSettle).toBe(afterReady);

    await assertUniqueVisibleProductHandles(page);

    expect(afterSettle).toBeGreaterThanOrEqual(stabilityCase.expectMinVisibleProducts);



    if (stabilityCase.expectExactVisibleProducts !== undefined) {

        expect(afterSettle).toBe(stabilityCase.expectExactVisibleProducts);

    }



    if (stabilityCase.expectVisibleTextAny !== undefined && stabilityCase.expectVisibleTextAny.length > 0) {

        await expect

            .poll(async () => {

                const visibleText = await page.locator('#product-grid .grid__item:not([hidden])').allInnerTexts();

                return visibleText.join('\n');

            }, { timeout: 45000 })

            .toMatch(

                new RegExp(

                    stabilityCase

                        .expectVisibleTextAny!.map((needle) => needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))

                        .join('|'),

                    'i',

                ),

            );

    }

    if (stabilityCase.expectAbsentTextAny !== undefined && stabilityCase.expectAbsentTextAny.length > 0) {
        const visibleText = (await page.locator('#product-grid .grid__item:not([hidden])').allInnerTexts()).join('\n');
        for (const needle of stabilityCase.expectAbsentTextAny) {
            expect(visibleText).not.toContain(needle);
        }
    }

    if (stabilityCase.expectCardTitleContains && stabilityCase.expectCardAbsentBadgeSelectors) {
        const card = page
            .locator('#product-grid .grid__item:not([hidden])')
            .filter({ hasText: stabilityCase.expectCardTitleContains })
            .first();
        await expect(card).toBeVisible();
        for (const selector of stabilityCase.expectCardAbsentBadgeSelectors) {
            await expect(card.locator(selector)).toHaveCount(0);
        }
    }

    if (stabilityCase.expectOnPhotoBadgeSelector) {
        await assertBadgesOverlapPhoto(
            page,
            stabilityCase.expectOnPhotoBadgeSelector,
            stabilityCase.expectOnPhotoBadgeMin ?? 1,
        );
    }

}



test.describe('Storefront MK collection filters — prefilter stability regressions', () => {

    test.setTimeout(120_000);

    for (const stabilityCase of FILTER_PREFILTER_STABILITY_CASES) {

        test(`${stabilityCase.handle}: ${stabilityCase.name}`, async ({ page }) => {

            await assertPrefilterStability(page, stabilityCase);

        });

    }

});



test.describe('Storefront MK collection filters — mega menu prefilter landing', () => {

    for (const prefilter of manifest.megaMenuPrefilterCases ?? []) {

        test(`${prefilter.name} pre-checks filter and updates title`, async ({ page }) => {

            await page.goto(`${STOREFRONT_BASE_URL}${prefilter.path}`, { waitUntil: 'domcontentloaded' });

            await waitForMkFiltersReady(page);



            const desktopCheckbox = page

                .locator('#main-collection-filters')

                .locator(prefilter.expectCheckboxSelector)

                .first();

            await expect(desktopCheckbox).toBeChecked();



            const mobileCheckbox = page.locator('#FacetsWrapperMobile').locator(prefilter.expectCheckboxSelector).first();

            await expect(mobileCheckbox).toBeChecked();



            await expect(page.locator('.ovs-ts-collection-title')).toContainText(prefilter.expectTitleContains);



            const minVisible = prefilter.expectMinVisibleProducts ?? 1;

            const count = await visibleGridCount(page);

            expect(count).toBeGreaterThanOrEqual(minVisible);

            await assertUniqueVisibleProductHandles(page);



            if (prefilter.expectVisibleTextAny !== undefined && prefilter.expectVisibleTextAny.length > 0) {

                await expect

                    .poll(async () => {

                        const visibleText = await page.locator('#product-grid .grid__item:not([hidden])').allInnerTexts();

                        return visibleText.join('\n');

                    }, { timeout: 45000 })

                    .toMatch(

                        new RegExp(

                            prefilter

                                .expectVisibleTextAny!.map((needle) => needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))

                                .join('|'),

                            'i',

                        ),

                    );

            }

        });

    }

});



test.describe('Storefront MK collection filters — layout smoke', () => {

    for (const collection of manifest.collections) {

        if (collection.mobileSmoke) {

            test(`${collection.handle}: mobile filters live in drawer only`, async ({ page }) => {

                await page.setViewportSize({ width: 390, height: 844 });

                await page.goto(`${STOREFRONT_BASE_URL}${collection.path}`, { waitUntil: 'domcontentloaded' });

                await waitForMkFiltersReady(page);



                const inline = page.locator(collection.mobileSmoke!.inlineFiltersHiddenSelector).first();

                await expect(inline).toBeHidden();



                await page.locator(collection.mobileSmoke!.openDrawerSelector).click();

                await expect(page.locator('.mobile-facets__inner')).toBeVisible();

                const drawerFilter = page.locator(collection.mobileSmoke!.drawerFilterSelector).first();

                await expect(drawerFilter).toBeVisible();

            });

        }



        if (collection.expectTitle) {

            test(`${collection.handle}: title and breadcrumb`, async ({ page }) => {

                await page.goto(`${STOREFRONT_BASE_URL}${collection.path}`, { waitUntil: 'domcontentloaded' });

                await expect(page.locator('.ovs-ts-collection-title')).toContainText(collection.expectTitle);

                if (collection.expectBreadcrumb) {

                    await expect(page.locator('.ovs-ts-breadcrumb')).toBeVisible();

                }

            });

        }

        if (collection.absentCheckboxSelectors && collection.absentCheckboxSelectors.length > 0) {

            test(`${collection.handle}: removed filter options stay gone`, async ({ page }) => {

                await page.goto(`${STOREFRONT_BASE_URL}${collection.path}`, { waitUntil: 'domcontentloaded' });

                await expect(page.locator('#main-collection-filters')).toBeVisible({ timeout: 20000 });

                for (const selector of collection.absentCheckboxSelectors ?? []) {

                    await expect(page.locator('#main-collection-filters').locator(selector)).toHaveCount(0);

                    await expect(page.locator('#FacetsWrapperMobile').locator(selector)).toHaveCount(0);

                }

                await expect(page.locator('#main-collection-filters .ovs-mk-franchise-input[data-ovs-mk-franchise="one_piece"]').first()).toBeAttached();

            });

        }

    }

});



test.describe('Storefront MK pre-orders filters', () => {

    test('pre-orders: default URL renders open products', async ({ page }) => {

        await page.goto(`${STOREFRONT_BASE_URL}${manifest.preorders.defaultPath}`, { waitUntil: 'networkidle' });

        await expect(page.locator('.ovs-ts-collection-title')).toContainText(manifest.preorders.expectTitle);

        await expect(page.locator('#product-grid')).not.toHaveClass(/ovs-po-filters-pending/);

        const count = await visibleGridCount(page);

        expect(count).toBeGreaterThanOrEqual(manifest.preorders.expectMinVisibleProducts);

        await assertUniqueVisibleProductHandles(page);

    });



    test('pre-orders: filtered URL renders products', async ({ page }) => {

        await page.goto(`${STOREFRONT_BASE_URL}${manifest.preorders.filteredPath}`, { waitUntil: 'networkidle' });

        await expect(page.locator('.ovs-ts-collection-title')).toContainText(manifest.preorders.expectTitle);

        await expect(page.locator('#product-grid')).not.toHaveClass(/ovs-po-filters-pending/);

        const count = await visibleGridCount(page);

        expect(count).toBeGreaterThanOrEqual(manifest.preorders.expectMinVisibleProducts);

        await assertUniqueVisibleProductHandles(page);

    });



    test('pre-orders: Other brand does not clone page-1 cards', async ({ page }) => {

        await page.goto(`${STOREFRONT_BASE_URL}${manifest.preorders.brandOtherPath}`, { waitUntil: 'networkidle' });

        await expect(page.locator('.ovs-ts-collection-title')).toContainText(manifest.preorders.expectTitle);

        await expect(page.locator('#product-grid')).not.toHaveClass(/ovs-po-filters-pending/, { timeout: 20000 });

        await page.waitForTimeout(3000);

        const visible = page.locator('#product-grid > .grid__item:not([hidden])');

        const handles = await visible.evaluateAll((items) =>

            items.map((item) => {

                const attr = item.getAttribute('data-ovs-po-handle') || '';

                if (attr !== '') {

                    return attr;

                }

                const href = item.querySelector('a[href*="/products/"]')?.getAttribute('href') || '';

                return href.match(/\/products\/([^/?#]+)/)?.[1] ?? '';

            }),

        );

        const unique = new Set(handles.filter((handle) => handle !== ''));

        expect(handles.length).toBe(unique.size);

        expect(unique.size).toBeGreaterThanOrEqual(manifest.preorders.expectMinVisibleProducts);

        const titleNeedle = manifest.preorders.brandOtherExpectTitleOnce;

        const titleHits = await visible.evaluateAll((items, needle) =>

            items.filter((item) => (item.textContent || '').includes(needle)).length,

            titleNeedle,

        );

        expect(titleHits).toBe(1);

        await assertUniqueVisibleProductHandles(page);

    });

    test('pre-orders: closed status includes RG Ground Type', async ({ page }) => {
        test.setTimeout(90_000);
        await page.goto(`${STOREFRONT_BASE_URL}${manifest.preorders.closedPath}`, { waitUntil: 'networkidle' });
        await expect(page.locator('.ovs-ts-collection-title')).toContainText(manifest.preorders.expectTitle);
        const grid = page.locator('#product-grid');
        await expect(grid).not.toHaveClass(/ovs-po-filters-pending/, { timeout: 20000 });
        const needle = new RegExp(
            manifest.preorders.expectClosedVisibleTextAny.map((value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'),
            'i',
        );
        const visibleCards = grid.locator('.grid__item:not([hidden])');
        for (let pageNumber = 0; pageNumber < 6; pageNumber += 1) {
            if ((await visibleCards.filter({ hasText: needle }).count()) > 0) {
                break;
            }
            const next = page.locator('#ovs-po-filter-pagination a[aria-label="Next page"]');
            if ((await next.count()) === 0) {
                break;
            }
            await next.click();
            await expect(grid).not.toHaveClass(/ovs-po-filters-pending/, { timeout: 20000 });
        }
        await expect(visibleCards.filter({ hasText: needle })).toHaveCount(1);
    });

});

test.describe('Storefront listing CTAs — every MK collection and listing page', () => {
    test.setTimeout(60_000);

    for (const listingCase of flattenMkListingCtaCases()) {
        test(listingCase.name, async ({ page }) => {
            test.setTimeout(listingCase.requireFullIndex ? 120_000 : 60_000);
            await page.setViewportSize({ width: 1400, height: 900 });
            await page.goto(`${STOREFRONT_BASE_URL}${listingCase.path}`, { waitUntil: 'domcontentloaded' });

            const gridSelector = listingCase.gridSelector ?? '#product-grid, ul.product-grid';
            const grid = page.locator(gridSelector).first();
            await expect(grid).toBeVisible({ timeout: 20000 });

            if (listingCase.requireFullIndex) {
                await waitForMkFiltersReady(page, { requireFullIndex: true });
                await page.waitForTimeout(3000);
            } else if (listingCase.waitForMkFilters) {
                await waitForMkFiltersReady(page);
                await page.waitForTimeout(1500);
            } else {
                await page.waitForTimeout(1500);
                const productCount = await page.locator(`${gridSelector} .grid__item a[href*="/products/"]`).count();
                if (productCount > 0 && !listingCase.expectNoCtas) {
                    await expect(
                        page.locator(`${gridSelector} .quick-add__submit, ${gridSelector} button[name="add"]`).first(),
                    ).toBeVisible({ timeout: 15000 });
                }
            }

            await assertVisibleListingCardsHaveCtas(page, listingCase);
        });
    }
});

test.describe('Storefront PDP CTAs — representative product pages', () => {
    for (const pdpCase of manifest.listingCtaPdpCases ?? []) {
        test(pdpCase.name, async ({ page }) => {
            await assertPdpHasListingCta(
                page,
                `${STOREFRONT_BASE_URL}${pdpCase.path}`,
                new RegExp(pdpCase.cta, 'i'),
            );
        });
    }
});



test.describe('Storefront listing uniqueness — one handle per card', () => {

    for (const listingCase of manifest.listingUniquenessCases ?? []) {

        test(listingCase.name, async ({ page }) => {

            await page.goto(`${STOREFRONT_BASE_URL}${listingCase.path}`, { waitUntil: 'networkidle' });

            await expect(page.locator('#product-grid')).toBeVisible();

            await page.waitForTimeout(2000);

            await assertUniqueVisibleProductHandles(page);

        });

    }

});


