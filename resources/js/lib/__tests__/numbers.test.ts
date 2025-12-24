import { describe, expect, it } from 'vitest';

import { parseNonNegativeIntOrNull } from '../numbers';

describe('parseNonNegativeIntOrNull', () => {
    it('returns null for empty input', () => {
        expect(parseNonNegativeIntOrNull('')).toBeNull();
        expect(parseNonNegativeIntOrNull('   ')).toBeNull();
    });

    it('parses normal digits', () => {
        expect(parseNonNegativeIntOrNull('5')).toBe(5);
        expect(parseNonNegativeIntOrNull('  12  ')).toBe(12);
    });

    it('parses full-width digits', () => {
        expect(parseNonNegativeIntOrNull('５')).toBe(5);
        expect(parseNonNegativeIntOrNull('１２３')).toBe(123);
    });

    it('parses common thousands separators', () => {
        expect(parseNonNegativeIntOrNull('1,234')).toBe(1234);
        expect(parseNonNegativeIntOrNull('1 234')).toBe(1234);
        expect(parseNonNegativeIntOrNull('1_234')).toBe(1234);
    });

    it('rejects non-integers and negative values', () => {
        expect(() => parseNonNegativeIntOrNull('-1')).toThrow();
        expect(() => parseNonNegativeIntOrNull('5.5')).toThrow();
        expect(() => parseNonNegativeIntOrNull('5e2')).toThrow();
        expect(() => parseNonNegativeIntOrNull('abc')).toThrow();
    });

    it('enforces max int (matches backend)', () => {
        expect(parseNonNegativeIntOrNull('2147483647')).toBe(2147483647);
        expect(() => parseNonNegativeIntOrNull('2147483648')).toThrow();
    });
});


