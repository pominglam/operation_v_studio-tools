import { expect, type Page } from '@playwright/test';

export type ListingUniquenessCase = {
    name: string;
    path: string;
};

export async function visibleProductHandles(page: Page): Promise<string[]> {
    return page.locator('#product-grid > .grid__item:not([hidden])').evaluateAll((items) =>
        items
            .filter((item) => !item.classList.contains('hidden') && item.getAttribute('aria-hidden') !== 'true')
            .map((item) => {
                const attrs = ['data-ovs-po-handle', 'data-ovs-mk-handle', 'data-product-handle'];
                for (const name of attrs) {
                    const value = item.getAttribute(name);
                    if (value) {
                        return value;
                    }
                }
                const href = item.querySelector('a[href*="/products/"]')?.getAttribute('href') || '';
                return href.match(/\/products\/([^/?#]+)/)?.[1] ?? '';
            })
            .filter((handle) => handle !== ''),
    );
}

export async function assertUniqueVisibleProductHandles(page: Page): Promise<void> {
    const handles = await visibleProductHandles(page);
    expect(new Set(handles).size, `duplicate product handles in listing: ${handles.join(', ')}`).toBe(handles.length);
}
