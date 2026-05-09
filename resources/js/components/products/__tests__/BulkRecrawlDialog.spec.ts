import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import BulkRecrawlDialog from '../BulkRecrawlDialog.vue';

describe('BulkRecrawlDialog', () => {
    it('shows Argama option unchecked by default', async () => {
        const wrapper = mount(BulkRecrawlDialog, {
            props: {
                open: true,
                selectedCount: 1,
                busy: false,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        expect(wrapper.text()).toContain('Argama PDP');
        const labels = wrapper.findAll('label');
        const argamaLabel = labels.find((label) => label.text().includes('Argama PDP'));
        expect(argamaLabel).toBeTruthy();
        expect(
            (argamaLabel!.find('input[type="checkbox"]').element as HTMLInputElement).checked,
        ).toBe(false);
    });

    it('shows Newtype option and includes it in confirm payload', async () => {
        const wrapper = mount(BulkRecrawlDialog, {
            props: {
                open: true,
                selectedCount: 1,
                busy: false,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        expect(wrapper.text()).toContain('Newtype PDP');
        expect(wrapper.text()).toContain('GundamHangar API');

        // Uncheck everything except Newtype, then confirm.
        const labels = wrapper.findAll('label');
        for (const label of labels) {
            const txt = label.text();
            const input = label.find('input[type="checkbox"]');
            if (!input.exists()) continue;
            const shouldCheck = txt.includes('Newtype');
            await input.setValue(shouldCheck);
        }

        const recrawlBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Recrawl');
        expect(recrawlBtn).toBeTruthy();
        await recrawlBtn!.trigger('click');

        const emitted = wrapper.emitted('confirm');
        expect(emitted).toBeTruthy();
        expect(emitted![0][0]).toEqual({ sources: ['newtype'] });
    });
});
