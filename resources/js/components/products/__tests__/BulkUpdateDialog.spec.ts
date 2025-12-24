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
        const shippedCheckbox = wrapper.findAll('label').find((l) => l.text().trim() === 'Shipped')!.find('input[type="checkbox"]');
        await shippedCheckbox.setValue(true);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Select at least one field to update.');
    });
});


