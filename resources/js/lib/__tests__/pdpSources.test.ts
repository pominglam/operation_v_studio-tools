import { describe, expect, it } from 'vitest';
import { descriptionSourceUrl } from '../pdpSources';

describe('descriptionSourceUrl', () => {
    it('uses explicit source_url when present', () => {
        const url = descriptionSourceUrl(
            { source: 'hlj', source_url: 'https://www.hlj.com/example' },
            { sku: '0170796', query: 'BB368' },
        );
        expect(url).toBe('https://www.hlj.com/example');
    });

    it('does not fall back to HLJ search when source_url is missing (backend should provide PDP)', () => {
        const url = descriptionSourceUrl(
            { source: 'hlj', source_url: null },
            { sku: '0170796', query: 'BB #368 00 (Double O) Gundam Seven Sword /G' },
        );
        expect(url).toBeNull();
    });
});


