import { describe, expect, it } from 'vitest';

import {
    purchaseOrderFilterMultiSelectOption,
    purchaseOrderPrimaryLabel,
    purchaseOrderSecondaryLabel,
} from '../purchaseOrderFilterOption';

describe('purchaseOrderFilterOption', () => {
    it('formats received PO with primary and secondary lines', () => {
        const po = {
            id: '2a6fe7e6-abcd-1234-5678-000000000000',
            vendor: 'Plamod',
            created_at: '2026-07-01T12:00:00Z',
            ordered_date: '2026-07-08',
            estimated_arrival_date: null,
            received_date: '2026-07-22',
            counts: { items: 71 },
        };

        expect(purchaseOrderPrimaryLabel(po)).toBe('Plamod · 71 items · 2a6fe7e6');
        expect(purchaseOrderSecondaryLabel(po)).toBe('Received Jul 22, 2026');
        expect(purchaseOrderFilterMultiSelectOption(po)).toMatchObject({
            value: po.id,
            label: 'Plamod · 71 items · 2a6fe7e6',
            subLabel: 'Received Jul 22, 2026',
            muted: false,
        });
    });

    it('formats open PO with ETA and ordered date on secondary line', () => {
        const po = {
            id: 'abc12345-abcd-1234-5678-000000000000',
            vendor: 'Plamod',
            created_at: '2026-07-29T15:41:00Z',
            ordered_date: '2026-07-08',
            estimated_arrival_date: '2026-04-10',
            received_date: null,
            counts: { items: 12 },
        };

        expect(purchaseOrderSecondaryLabel(po)).toBe('Not arrived · ETA Apr 10, 2026 · ordered Jul 8, 2026');
        expect(purchaseOrderFilterMultiSelectOption(po).muted).toBe(true);
    });

    it('falls back to created timestamp when open PO has no ETA or ordered date', () => {
        const po = {
            id: 'draft000-abcd-1234-5678-000000000000',
            vendor: 'Other/multi',
            created_at: '2026-07-29T15:41:00Z',
            ordered_date: null,
            estimated_arrival_date: null,
            received_date: null,
            counts: { items: 3 },
        };

        expect(purchaseOrderSecondaryLabel(po)).toMatch(/^Not arrived · created /);
    });
});
