import { describe, expect, it } from 'vitest';

import {
    collectCartReportRetryableSkus,
    filterPlamodRestockNewRows,
    formatPlamodRestockCartRetryConfirmMessage,
    formatPlamodRestockOrderVerifyHeadline,
    isCartReportLineRetryable,
    isOrderVerifyLineMismatch,
    type PlamodRestockCartReportLine,
    type PlamodRestockNewRow,
} from '../plamodRestock';

describe('cart report retry helpers', () => {
    const lines: PlamodRestockCartReportLine[] = [
        { sku: 'A', requested_qty: 2, verification_status: 'verified' },
        { sku: 'B', requested_qty: 10, verification_status: 'missing' },
        { sku: 'C', requested_qty: 5, verification_status: 'add_failed' },
        { sku: 'D', requested_qty: 3, verification_status: 'partial' },
        { sku: 'E', requested_qty: 4, verification_status: 'over_added' },
    ];

    it('marks every non-exact cart result as retryable', () => {
        expect(isCartReportLineRetryable('missing')).toBe(true);
        expect(isCartReportLineRetryable('add_failed')).toBe(true);
        expect(isCartReportLineRetryable('partial')).toBe(true);
        expect(isCartReportLineRetryable('over_added')).toBe(true);
        expect(isCartReportLineRetryable('verified')).toBe(false);
    });

    it('collects retryable skus from report lines', () => {
        expect(collectCartReportRetryableSkus(lines)).toEqual(['B', 'C', 'D', 'E']);
    });

    it('explains that retries set the exact requested total', () => {
        const message = formatPlamodRestockCartRetryConfirmMessage(['C', 'D'], lines);
        expect(message).toContain('Set 2 mismatched line(s)');
        expect(message).toContain('exact requested final quantity');
        expect(message).toContain('lowered');
    });
});

describe('full order verify helpers', () => {
    it('formats a matching order headline', () => {
        expect(
            formatPlamodRestockOrderVerifyHeadline({
                requested_lines: 3,
                verified: 2,
                already_satisfied: 1,
                partial: 0,
                over_added: 0,
                missing: 0,
                add_failed: 0,
                all_verified: true,
                extra_cart_lines: 0,
                order_matches_cart: true,
            }),
        ).toBe('PLAMOD cart matches all 3 order line(s).');
    });

    it('flags mismatches and extra cart lines in headline', () => {
        const headline = formatPlamodRestockOrderVerifyHeadline({
            requested_lines: 5,
            verified: 3,
            already_satisfied: 0,
            partial: 1,
            over_added: 0,
            missing: 1,
            add_failed: 0,
            all_verified: false,
            extra_cart_lines: 2,
            order_matches_cart: false,
        });
        expect(headline).toContain('3/5 lines match');
        expect(headline).toContain('2 extra cart line(s)');
    });

    it('treats only verified and already satisfied as matching', () => {
        expect(isOrderVerifyLineMismatch('verified')).toBe(false);
        expect(isOrderVerifyLineMismatch('already_satisfied')).toBe(false);
        expect(isOrderVerifyLineMismatch('missing')).toBe(true);
    });
});

describe('new product filters', () => {
    it('shows only dismissed products when dismissed-only is enabled', () => {
        const base = {
            barcode: null,
            series: null,
            category: null,
            release_date: null,
            release_date_label: null,
            is_recent_release: false,
            order_qty: null,
            planned_maintain_qty: null,
            last_landed_cost: null,
            new_landed_cost: null,
            line_total: null,
            cost_delta_high: false,
            cost_delta_percent: null,
            price_missing: false,
            image_url: null,
            plamod_pdp_url: null,
        };
        const rows: PlamodRestockNewRow[] = [
            { ...base, sku: 'DISMISSED', product_name: 'Dismissed kit', status: 'dismissed' },
            { ...base, sku: 'UNDECIDED', product_name: 'Undecided kit', status: 'undecided' },
        ];

        expect(
            filterPlamodRestockNewRows(rows, {
                search: '',
                undecidedOnly: false,
                laterOnly: false,
                dismissedOnly: true,
                includedOnly: false,
                recentOnly: false,
                series: '',
            }).map((row) => row.sku),
        ).toEqual(['DISMISSED']);
    });
});
