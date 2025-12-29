import { describe, expect, it } from 'vitest';
import { clearPageState, loadPageState, savePageState } from '../pageState';

describe('pageState', () => {
    it('saves and loads JSON state', () => {
        const key = 'test:page_state';
        clearPageState(key);

        savePageState(key, { a: 1, b: 'x' });
        const loaded = loadPageState<{ a: number; b: string }>(key);

        expect(loaded).toEqual({ a: 1, b: 'x' });
    });

    it('returns null on invalid JSON', () => {
        const key = 'test:page_state:bad';
        window.localStorage.setItem(key, '{not json');
        const loaded = loadPageState<Record<string, unknown>>(key);
        expect(loaded).toBeNull();
    });
});


