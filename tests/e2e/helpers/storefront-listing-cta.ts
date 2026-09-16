import { expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { loadStorefrontMkCollectionFiltersManifest } from './storefront-mk-collection-filters-manifest';
import { loadStorefrontTsCollectionFiltersManifest } from './storefront-ts-collection-filters-manifest';

const HELPERS_DIR = path.dirname(fileURLToPath(import.meta.url));

export const LISTING_CTA_SELECTOR = [
    '.quick-add__submit',
    '.product-form__submit',
    'button[name="add"]',
    '[data-ovs-listing-cta]',
].join(', ');

export const LISTING_CTA_LABEL = /add to cart|sold out|pre-order|preorder closed|choose options/i;

export type ListingCtaExpectation = {
    titleContains: string;
    cta: string;
};

export type ListingCtaCase = {
    name: string;
    path: string;
    gridSelector?: string;
    waitForMkFilters?: boolean;
    requireFullIndex?: boolean;
    allowEmpty?: boolean;
    expectMinVisibleProducts?: number;
    expectCardCtas?: ListingCtaExpectation[];
    expectNoCtas?: boolean;
    expectNoVisibleText?: string[];
};

function themeRoot(): string {
    const fromEnv = process.env.OVS_SHOPIFY_THEME_PATH?.trim();
    if (fromEnv && fs.existsSync(fromEnv)) {
        return path.resolve(fromEnv);
    }

    return path.resolve(HELPERS_DIR, '../../../../ovs-shopify-theme');
}

export function readMkFilterHandlesFromTheme(): string[] {
    const liquid = fs.readFileSync(
        path.join(themeRoot(), 'snippets/ovs-model-kit-filter-handles.liquid'),
        'utf8',
    );
    const match = liquid.match(/ovs_mk_filter_handles_csv\s*=\s*'([^']+)'/);

    return (match?.[1] ?? '')
        .split(',')
        .map((handle) => handle.trim())
        .filter((handle) => handle !== '');
}

function mergeListingCtaCases(cases: ListingCtaCase[]): ListingCtaCase[] {
    const byPath = new Map<string, ListingCtaCase>();
    for (const listingCase of cases) {
        const existing = byPath.get(listingCase.path);
        byPath.set(listingCase.path, existing ? { ...existing, ...listingCase } : listingCase);
    }

    return [...byPath.values()];
}

export function flattenMkListingCtaCases(): ListingCtaCase[] {
    const manifest = loadStorefrontMkCollectionFiltersManifest();
    const named = manifest.listingCtaCases ?? [];
    const generated: ListingCtaCase[] = [
        {
            name: 'Model kits hub',
            path: '/collections/model-kits',
            waitForMkFilters: true,
        },
        ...readMkFilterHandlesFromTheme().map((handle) => ({
            name: `MK ${handle}`,
            path: `/collections/${handle}`,
            allowEmpty: true,
        })),
        {
            name: 'Pre-orders',
            path: '/collections/pre-orders',
            allowEmpty: false,
        },
        {
            name: 'Homepage featured listings have no CTA',
            path: '/',
            gridSelector: 'ul.product-grid',
            expectNoCtas: true,
        },
        {
            name: 'Search SNAA',
            path: '/search?q=snaa',
            gridSelector: 'ul.product-grid',
            allowEmpty: true,
        },
        {
            name: 'Keychains',
            path: '/collections/keychain',
            allowEmpty: true,
        },
        {
            name: 'CCS Toys',
            path: '/collections/ccs-toys',
            allowEmpty: true,
        },
    ];

    return mergeListingCtaCases([...generated, ...named]);
}

export function flattenTsListingCtaCases(): ListingCtaCase[] {
    const manifest = loadStorefrontTsCollectionFiltersManifest();
    const named = manifest.listingCtaCases ?? [];
    const generated: ListingCtaCase[] = [
        {
            name: 'Tools and supplies hub',
            path: '/collections/tools-and-supplies',
            allowEmpty: true,
        },
        ...manifest.collectionsWithCheckboxFilters.map((collection) => ({
            name: `T&S ${collection.handle}`,
            path: collection.path,
            allowEmpty: true,
        })),
        ...(manifest.listingUniquenessCases ?? []).map((listingCase) => ({
            name: listingCase.name,
            path: listingCase.path,
        })),
    ];

    return mergeListingCtaCases([...generated, ...named]);
}

export async function assertVisibleListingCardsHaveCtas(
    page: Page,
    listingCase: ListingCtaCase,
): Promise<{ count: number; labels: string[] }> {
    const gridSelector = listingCase.gridSelector ?? '#product-grid, ul.product-grid';
    const result = await page.evaluate(
        ({ selector, ctaSelector }) => {
            const roots = [...document.querySelectorAll(selector)];
            const items = roots
                .flatMap((root) => [...root.querySelectorAll('.grid__item')])
                .filter((item) => {
                    const element = item as HTMLElement;
                    if (element.hasAttribute('hidden') || element.hidden) {
                        return false;
                    }
                    if (window.getComputedStyle(element).display === 'none') {
                        return false;
                    }

                    return Boolean(element.querySelector('a[href*="/products/"]'));
                });

            const missing = items
                .filter((item) => !item.querySelector(ctaSelector))
                .map(
                    (item) =>
                        (item.querySelector('.card__heading, a[href*="/products/"]')?.textContent || '')
                            .replace(/\s+/g, ' ')
                            .trim(),
                );

            const labels = items.map((item) =>
                (item.querySelector(ctaSelector)?.textContent || '').replace(/\s+/g, ' ').trim(),
            );

            const cardCtas = items.map((item) => {
                const title = (item.querySelector('.card__heading')?.textContent || '')
                    .replace(/\s+/g, ' ')
                    .trim();
                const cta = (item.querySelector(ctaSelector)?.textContent || '').replace(/\s+/g, ' ').trim();

                return { title, cta };
            });

            return { count: items.length, missing, labels, cardCtas };
        },
        { selector: gridSelector, ctaSelector: LISTING_CTA_SELECTOR },
    );

    if (result.count === 0 && listingCase.allowEmpty) {
        return { count: 0, labels: [] };
    }

    expect(result.count, `${listingCase.name} (${listingCase.path}) had no product cards`).toBeGreaterThan(0);
    if (listingCase.expectMinVisibleProducts !== undefined) {
        expect(result.count).toBeGreaterThanOrEqual(listingCase.expectMinVisibleProducts);
    }

    if (listingCase.expectNoCtas) {
        const withCta = result.cardCtas.filter((card) => card.cta !== '');
        expect(
            withCta,
            `${listingCase.name} homepage cards must not show listing CTAs: ${withCta.map((card) => card.title).join('; ')}`,
        ).toEqual([]);
    }

    if ((listingCase.expectNoVisibleText ?? []).length > 0) {
        const banned = listingCase.expectNoVisibleText ?? [];
        const hits = await page.evaluate(
            ({ selector, phrases }) => {
                const roots = [...document.querySelectorAll(selector)];
                return roots.flatMap((root) => {
                    const text = (root.textContent || '').replace(/\s+/g, ' ');
                    return phrases.filter((phrase) => text.includes(phrase));
                });
            },
            { selector: gridSelector, phrases: banned },
        );
        expect(hits, `${listingCase.name} must not show ${banned.join(', ')}`).toEqual([]);
    }

    if (listingCase.expectNoCtas) {
        return { count: result.count, labels: [] };
    }

    expect(result.missing, `${listingCase.name} cards missing CTA: ${result.missing.join('; ')}`).toEqual([]);

    for (const label of result.labels) {
        expect(label, `${listingCase.name} unexpected CTA "${label}"`).toMatch(LISTING_CTA_LABEL);
    }

    for (const expected of listingCase.expectCardCtas ?? []) {
        const match = result.cardCtas.find((card) =>
            card.title.toLowerCase().includes(expected.titleContains.toLowerCase()),
        );
        expect(match, `${listingCase.name} missing card titled like "${expected.titleContains}"`).toBeTruthy();
        expect(match?.cta, `${expected.titleContains} CTA`).toMatch(new RegExp(expected.cta, 'i'));
    }

    return { count: result.count, labels: result.labels };
}

export async function assertPdpHasListingCta(page: Page, path: string, expected?: RegExp): Promise<void> {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    const button = page.locator('.product-form__submit, button[name="add"]').first();
    await expect(button).toBeVisible({ timeout: 15000 });
    if (expected) {
        await expect(button).toHaveText(expected);
    }
}
