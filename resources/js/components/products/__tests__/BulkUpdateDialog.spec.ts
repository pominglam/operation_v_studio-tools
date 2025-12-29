import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import BulkUpdateDialog from '../BulkUpdateDialog.vue';

describe('BulkUpdateDialog', () => {
    it('clears validation error when the user edits a field', async () => {
        const wrapper = mount(BulkUpdateDialog, {
            props: {
                open: true,
                selectedCount: 2,
                busy: false,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        const updateBtn = wrapper.findAll('button').find((b) => b.text().includes('Update selected'));
        expect(updateBtn, 'Expected Update selected button').toBeTruthy();
        await updateBtn!.trigger('click');

        expect(wrapper.emitted('confirm')).toBeUndefined();
        expect(wrapper.text()).toContain('Select at least one field to update.');

        // Any edit should clear the stale validation message
        const shippedCheckbox = wrapper
            .findAll('label')
            .find((l) => l.text().trim() === 'Shipped')!
            .find('input[type="checkbox"]');
        await shippedCheckbox.setValue(true);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Select at least one field to update.');
    });

    it('includes published_on_shopify in payload when applied', async () => {
        const wrapper = mount(BulkUpdateDialog, {
            props: {
                open: true,
                selectedCount: 2,
                busy: false,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        const label = wrapper
            .findAll('label')
            .find((l) => l.text().trim() === 'Published on Shopify');
        expect(label, 'Expected Published on Shopify label').toBeTruthy();

        const checkbox = label!.find('input[type="checkbox"]');
        await checkbox.setValue(true);

        const select = wrapper.find('select');
        await select.setValue('true');

        const updateBtn = wrapper.findAll('button').find((b) => b.text().includes('Update selected'));
        expect(updateBtn, 'Expected Update selected button').toBeTruthy();
        await updateBtn!.trigger('click');

        const emitted = wrapper.emitted('confirm');
        expect(emitted, 'Expected confirm emission').toBeTruthy();
        expect(emitted![0][0]).toMatchObject({ changes: { published_on_shopify: true } });
    });
});


