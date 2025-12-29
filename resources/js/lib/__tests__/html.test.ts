import { describe, expect, it } from 'vitest';
import { decodeHtmlEntitiesDeep } from '../html';

describe('decodeHtmlEntitiesDeep', () => {
    it('decodes double-encoded entities', () => {
        const input = 'Two years&amp;nbsp;&amp;nbsp;after it wasn&amp;#39;t enough';
        const out = decodeHtmlEntitiesDeep(input);

        expect(out).toContain("wasn't");
        expect(out).not.toContain('&amp;#39;');
        expect(out).not.toContain('&amp;nbsp;');
    });
});


