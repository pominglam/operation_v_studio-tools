import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ColumnHeaderHelp from '../ColumnHeaderHelp.vue';

describe('ColumnHeaderHelp', () => {
  it('shows help text when the info button is clicked', async () => {
    const wrapper = mount(ColumnHeaderHelp, {
      props: { label: 'Qty on open POs not yet received.' },
    });

    expect(wrapper.find('[data-testid="column-header-help-popover"]').exists()).toBe(false);

    await wrapper.get('[data-testid="column-header-help-button"]').trigger('click');

    const popover = wrapper.get('[data-testid="column-header-help-popover"]');
    expect(popover.text()).toContain('Qty on open POs not yet received.');
  });
});
