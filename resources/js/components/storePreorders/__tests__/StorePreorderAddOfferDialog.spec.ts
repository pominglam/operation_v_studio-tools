import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import StorePreorderAddOfferDialog from '../StorePreorderAddOfferDialog.vue';

const apiGet = vi.fn();
const apiPost = vi.fn();

vi.mock('../../../lib/api', () => ({
    api: {
        get: (...args: unknown[]) => apiGet(...args),
        post: (...args: unknown[]) => apiPost(...args),
    },
    extractApiError: (err: unknown) => String(err),
}));

describe('StorePreorderAddOfferDialog', () => {
    beforeEach(() => {
        apiGet.mockReset();
        apiPost.mockReset();
        apiGet.mockResolvedValue({
            data: { data: { default_deposit_percent: '20' } },
        });
    });

    function mountDialog() {
        return mount(StorePreorderAddOfferDialog, {
            props: { open: true, busy: false },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });
    }

    it('stays open when sell $ is clicked and typed after a click that finishes on the backdrop', async () => {
        const wrapper = mountDialog();
        await wrapper.vm.$nextTick();

        const sell = wrapper.get('[data-testid="store-preorder-add-sell"]');
        const overlay = wrapper.get('[data-testid="store-preorder-add-offer-dialog"]');

        await sell.trigger('pointerdown');
        await sell.setValue('89.99');
        await overlay.trigger('click');

        expect(wrapper.emitted('cancel')).toBeUndefined();
        expect((sell.element as HTMLInputElement).value).toBe('89.99');
        expect(wrapper.find('[data-testid="store-preorder-add-offer-dialog"]').exists()).toBe(true);

        wrapper.unmount();
    });

    it('closes when press and release are both on the dimmed backdrop', async () => {
        const wrapper = mountDialog();
        await wrapper.vm.$nextTick();

        const overlay = wrapper.get('[data-testid="store-preorder-add-offer-dialog"]');
        await overlay.trigger('pointerdown');
        await overlay.trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);

        wrapper.unmount();
    });
});
