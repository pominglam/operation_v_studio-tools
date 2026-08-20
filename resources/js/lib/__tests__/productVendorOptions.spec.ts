import { describe, expect, it } from 'vitest';

import { mergeVendorOption, vendorChoicesIncludingDraft } from '../productVendorOptions';

describe('productVendorOptions', () => {
    it('keeps persisted options unchanged while the user is still typing', () => {
        const options = ['Plamod', 'Stedi', 'Water Decals'];

        expect(vendorChoicesIncludingDraft(options, 'Wa')).toEqual([
            'Plamod',
            'Stedi',
            'Wa',
            'Water Decals',
        ]);
        expect(vendorChoicesIncludingDraft(options, 'Water D')).toEqual([
            'Plamod',
            'Stedi',
            'Water D',
            'Water Decals',
        ]);
        expect(options).toEqual(['Plamod', 'Stedi', 'Water Decals']);
    });

    it('registers a committed vendor once after save', () => {
        const options = mergeVendorOption(['Plamod'], 'Water Decals');
        expect(options).toEqual(['Plamod', 'Water Decals']);

        expect(mergeVendorOption(options, 'Water Decals')).toEqual(['Plamod', 'Water Decals']);
    });

    it('includes the active draft in datalist choices without mutating stored options', () => {
        const stored = ['Plamod', 'Water Decals'];
        expect(vendorChoicesIncludingDraft(stored, 'Water D')).toEqual([
            'Plamod',
            'Water D',
            'Water Decals',
        ]);
        expect(stored).toEqual(['Plamod', 'Water Decals']);
    });
});
