import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createRouter, createWebHistory } from 'vue-router';
import AppNav from '../AppNav.vue';

describe('AppNav', () => {
    it('renders navigation links', async () => {
        const router = createRouter({
            history: createWebHistory(),
            routes: [
                { path: '/products', name: 'products', component: { template: '<div />' } },
                {
                    path: '/price-research',
                    name: 'price-research',
                    component: { template: '<div />' },
                },
                { path: '/maintenance', name: 'maintenance', component: { template: '<div />' } },
                { path: '/restocking/plamod', name: 'plamod-restock', component: { template: '<div />' } },
            ],
        });

        router.push('/products');
        await router.isReady();

        const wrapper = mount(AppNav, {
            global: { plugins: [router] },
        });

        expect(wrapper.text()).toContain('Products');
        expect(wrapper.text()).toContain('Pricing');
        expect(wrapper.text()).toContain('Restock');
        expect(wrapper.text()).toContain('Maintenance');
    });
});
