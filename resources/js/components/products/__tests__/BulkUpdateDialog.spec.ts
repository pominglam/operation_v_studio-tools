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

        const updateBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Update selected'));
        expect(updateBtn, 'Expected Update selected button').toBeTruthy();
        await updateBtn!.trigger('click');

        expect(wrapper.emitted('confirm')).toBeUndefined();
        expect(wrapper.text()).toContain('Select at least one field to update.');

        // Any edit should clear the stale validation message
        const skuCheckbox = wrapper
            .findAll('label')
            .find((l) => l.text().trim() === 'SKU')!
            .find('input[type="checkbox"]');
        await skuCheckbox.setValue(true);
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

        const updateBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Update selected'));
        expect(updateBtn, 'Expected Update selected button').toBeTruthy();
        await updateBtn!.trigger('click');

        const emitted = wrapper.emitted('confirm');
        expect(emitted, 'Expected confirm emission').toBeTruthy();
        expect(emitted![0][0]).toMatchObject({ changes: { published_on_shopify: true } });
    });

    it('includes latest_arrival in payload when applied', async () => {
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

        const label = wrapper.findAll('label').find((l) => l.text().trim() === 'Latest arrival');
        expect(label, 'Expected Latest arrival label').toBeTruthy();

        const checkbox = label!.find('input[type="checkbox"]');
        await checkbox.setValue(true);

        const selects = wrapper.findAll('select');
        const latestArrivalSelect = selects.at(1);
        expect(latestArrivalSelect, 'Expected Latest arrival select').toBeTruthy();
        await latestArrivalSelect!.setValue('true');

        const updateBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Update selected'));
        expect(updateBtn, 'Expected Update selected button').toBeTruthy();
        await updateBtn!.trigger('click');

        const emitted = wrapper.emitted('confirm');
        expect(emitted, 'Expected confirm emission').toBeTruthy();
        expect(emitted![0][0]).toMatchObject({ changes: { latest_arrival: true } });
    });

    it('includes archived in payload when archive status is applied', async () => {
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

        await wrapper.find('[data-testid="bulk-archive-status-apply"]').setValue(true);
        await wrapper.find('[data-testid="bulk-archive-status-select"]').setValue('unarchive');

        const updateBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Update selected'));
        expect(updateBtn, 'Expected Update selected button').toBeTruthy();
        await updateBtn!.trigger('click');

        const emitted = wrapper.emitted('confirm');
        expect(emitted, 'Expected confirm emission').toBeTruthy();
        expect(emitted![0][0]).toMatchObject({ changes: { archived: false } });
    });

    it('includes grade/scale/series in payload when applied', async () => {
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

        await wrapper.find('[data-testid="bulk-grade-apply"]').setValue(true);
        await wrapper.find('[data-testid="bulk-grade-value"]').setValue('RG');

        await wrapper.find('[data-testid="bulk-scale-apply"]').setValue(true);
        await wrapper.find('[data-testid="bulk-scale-value"]').setValue('1/144');

        await wrapper.find('[data-testid="bulk-series-apply"]').setValue(true);
        await wrapper.find('[data-testid="bulk-series-value"]').setValue('Gundam Seed');

        const updateBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Update selected'));
        expect(updateBtn, 'Expected Update selected button').toBeTruthy();
        await updateBtn!.trigger('click');

        const emitted = wrapper.emitted('confirm');
        expect(emitted, 'Expected confirm emission').toBeTruthy();
        expect(emitted![0][0]).toMatchObject({
            changes: { grade: 'RG', scale: '1/144', series: 'Gundam Seed' },
        });
    });

    it('includes available qty in payload when applied (supports 0)', async () => {
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

        const label = wrapper.findAll('label').find((l) => l.text().trim() === 'Available qty');
        expect(label, 'Expected Available qty label').toBeTruthy();

        await wrapper.find('[data-testid="bulk-available-apply"]').setValue(true);
        await wrapper.find('[data-testid="bulk-available-value"]').setValue('0');

        const updateBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Update selected'));
        expect(updateBtn, 'Expected Update selected button').toBeTruthy();
        await updateBtn!.trigger('click');

        const emitted = wrapper.emitted('confirm');
        expect(emitted, 'Expected confirm emission').toBeTruthy();
        expect(emitted![0][0]).toMatchObject({ changes: { available: 0 } });
    });

    it('uses datalist suggestions for vendor/type/grade/scale/series while still allowing free text', async () => {
        const wrapper = mount(BulkUpdateDialog, {
            props: {
                open: true,
                selectedCount: 1,
                busy: false,
                vendorOptions: ['Plamod', 'Stedi'],
                typeOptions: ['HG', 'TOOLS'],
                gradeOptions: ['HG', 'RG'],
                scaleOptions: ['1/144', '1/100'],
                seriesOptions: ['Gundam Wing'],
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        expect(wrapper.find('datalist#bulk-update-vendor-options').exists()).toBe(true);
        expect(wrapper.find('datalist#bulk-update-type-options').exists()).toBe(true);
        expect(wrapper.find('datalist#bulk-update-grade-options').exists()).toBe(true);
        expect(wrapper.find('datalist#bulk-update-scale-options').exists()).toBe(true);
        expect(wrapper.find('datalist#bulk-update-series-options').exists()).toBe(true);

        // Still a normal input; users can type a value not present in options.
        const vendorInput = wrapper.findAll('label').find((l) => l.text().trim() === 'Vendor')!
            .element.nextElementSibling as HTMLInputElement | null;
        expect(vendorInput?.getAttribute('list')).toBe('bulk-update-vendor-options');
    });
});
