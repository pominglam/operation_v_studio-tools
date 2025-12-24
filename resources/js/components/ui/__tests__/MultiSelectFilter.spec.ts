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
});


