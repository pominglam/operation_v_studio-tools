import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import MultiSelectFilter from '../MultiSelectFilter.vue';

describe('MultiSelectFilter', () => {
    it('selects all options when Select all is clicked', async () => {
        const wrapper = mount(MultiSelectFilter, {
            props: {
                label: 'Type',
                options: [
                    { value: 'HG', label: 'HG' },
                    { value: 'MG', label: 'MG' },
                ],
                modelValue: [],
            },
        });

        await wrapper.find('button').trigger('click'); // open
        const selectAll = wrapper.findAll('input[type="checkbox"]').at(0)!;
        await selectAll.setValue(true);

        const emitted = wrapper.emitted('update:modelValue');
        expect(emitted).toBeTruthy();
        expect(emitted![0][0]).toEqual(['HG', 'MG']);
    });

    it('clears selection when Select all is unchecked', async () => {
        const wrapper = mount(MultiSelectFilter, {
            props: {
                label: 'Type',
                options: [
                    { value: 'HG', label: 'HG' },
                    { value: 'MG', label: 'MG' },
                ],
                modelValue: ['HG', 'MG'],
            },
        });

        await wrapper.find('button').trigger('click'); // open
        const selectAll = wrapper.findAll('input[type="checkbox"]').at(0)!;
        await selectAll.setValue(false);

        const emitted = wrapper.emitted('update:modelValue');
        expect(emitted).toBeTruthy();
        expect(emitted![0][0]).toEqual([]);
    });

    it('renders subLabel and matches search against primary and secondary lines', async () => {
        const wrapper = mount(MultiSelectFilter, {
            props: {
                label: 'PO',
                options: [
                    {
                        value: 'po-1',
                        label: 'Plamod · 71 items · 2a6fe7e6',
                        subLabel: 'Not arrived · ETA Apr 10, 2026 · ordered Jul 8, 2026',
                    },
                    {
                        value: 'po-2',
                        label: 'Stedi · 12 items · abcdef01',
                        subLabel: 'Received Jul 22, 2026',
                    },
                ],
                modelValue: [],
                testId: 'PO',
            },
        });

        await wrapper.find('button').trigger('click');
        expect(wrapper.text()).toContain('Plamod · 71 items · 2a6fe7e6');
        expect(wrapper.text()).toContain('Not arrived · ETA Apr 10, 2026 · ordered Jul 8, 2026');

        await wrapper.get('[data-testid="PO-search"]').setValue('apr 10');
        expect(wrapper.text()).toContain('Plamod · 71 items · 2a6fe7e6');
        expect(wrapper.text()).not.toContain('Stedi · 12 items · abcdef01');
    });
});


