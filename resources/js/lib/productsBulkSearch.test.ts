import { describe, expect, it } from 'vitest';
import { mergeById, splitBulkSearchTerms } from './productsBulkSearch';

describe('splitBulkSearchTerms', () => {
    it('splits by newline, trims, drops blanks, and dedupes case-insensitively', () => {
        const input = `
          MS HAND 01 GRAY (1/144)
          MS HAND 01 GRAY (1/144)
          ms hand 01 gray (1/144)

          OLFA Knife
        `;

        expect(splitBulkSearchTerms(input)).toEqual(['MS HAND 01 GRAY (1/144)', 'OLFA Knife']);
    });

    it('caps results to maxLines', () => {
        const input = ['a', 'b', 'c', 'd'].join('\n');
        expect(splitBulkSearchTerms(input, 2)).toEqual(['a', 'b']);
    });
});

describe('mergeById', () => {
    it('dedupes by id and preserves first-seen ordering', () => {
        const a = [{ id: '1', v: 'a' }, { id: '2', v: 'b' }];
        const b = [{ id: '2', v: 'b2' }, { id: '3', v: 'c' }];

        expect(mergeById([a, b])).toEqual([
            { id: '1', v: 'a' },
            { id: '2', v: 'b' },
            { id: '3', v: 'c' },
        ]);
    });
});

