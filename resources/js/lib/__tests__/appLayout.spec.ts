import { describe, expect, it } from 'vitest';

import { isFullWidthAppPath } from '../appLayout';

describe('isFullWidthAppPath', () => {
    it('uses full viewport width for wide data tables', () => {
        expect(isFullWidthAppPath('/price-research')).toBe(true);
        expect(isFullWidthAppPath('/products')).toBe(true);
        expect(isFullWidthAppPath('/purchase-orders/00000000-0000-0000-0000-000000000001')).toBe(
            true,
        );
    });

    it('keeps standard width for other pages', () => {
        expect(isFullWidthAppPath('/purchase-orders')).toBe(false);
        expect(isFullWidthAppPath('/maintenance')).toBe(false);
    });
});
