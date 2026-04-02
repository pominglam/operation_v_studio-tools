import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// Mock the shared axios instance used by the drawer.
vi.mock('../../../lib/api', () => {
    return {
        api: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
        },
    };
});

import { api } from '../../../lib/api';
import PlamodDrawer from '../PlamodDrawer.vue';

describe('PlamodDrawer', () => {
    it('renders an editable description box for the Other source', async () => {
        (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            data: {
                data: {
                    preferred_description_source: null,
                    contents: [
                        {
                            source: 'hlj',
                            source_url: 'https://example.com/hlj',
                            title: 'HLJ Title',
                            description_html: '<p>HLJ desc</p>',
                            attributes: null,
                            updated_at: '2026-02-04T00:00:00Z',
                        },
                        {
                            source: 'other',
                            source_url: 'https://example.com/other',
                            title: 'Other Title',
                            description_html: '<p>Hello <b>world</b>.</p>',
                            attributes: null,
                            updated_at: '2026-02-04T00:00:00Z',
                        },
                    ],
                    assets: [],
                },
            },
        });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-desc-1',
                productSku: 'SKU-DESC-1',
                productName: 'Grid Name 1',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const textarea = wrapper.find('[data-testid="description-editor-manual"]');
        expect(textarea.exists()).toBe(true);
        expect((textarea.element as HTMLTextAreaElement).value).toContain('Hello world.');

        // "Other" is a real source card and should be read-only.
        expect(wrapper.find('[data-testid="description-editor-other"]').exists()).toBe(false);

        await textarea.setValue('My edited description');
        expect((textarea.element as HTMLTextAreaElement).value).toBe('My edited description');
    });

    it('can sort exporting photos by source (Plamod -> HLJ -> Newtype -> GundamPlanet)', async () => {
        (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            data: {
                data: {
                    preferred_description_source: null,
                    contents: [],
                    assets: [
                        // Start in an arbitrary order (sort_order drives initial ordering).
                        {
                            id: 1,
                            source: 'gundamplanet',
                            kind: 'image',
                            filename: 'gp-on.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'a'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 1,
                            download_url: 'https://example.com/dl/gp-on.jpg',
                            view_url: 'https://example.com/view/gp-on.jpg',
                        },
                        {
                            id: 2,
                            source: 'hlj',
                            kind: 'image',
                            filename: 'hlj-on.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'b'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 2,
                            download_url: 'https://example.com/dl/hlj-on.jpg',
                            view_url: 'https://example.com/view/hlj-on.jpg',
                        },
                        {
                            id: 3,
                            source: 'plamod',
                            kind: 'image',
                            filename: 'plamod-on.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'c'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 3,
                            download_url: 'https://example.com/dl/plamod-on.jpg',
                            view_url: 'https://example.com/view/plamod-on.jpg',
                        },
                        {
                            id: 4,
                            source: 'newtype',
                            kind: 'image',
                            filename: 'newtype-on.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'd'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 4,
                            download_url: 'https://example.com/dl/newtype-on.jpg',
                            view_url: 'https://example.com/view/newtype-on.jpg',
                        },
                        // Disabled image should remain at the end (not part of exporting sort).
                        {
                            id: 5,
                            source: 'plamod',
                            kind: 'image',
                            filename: 'plamod-off.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'e'.repeat(64),
                            shopify_enabled: false,
                            sort_order: 5,
                            download_url: 'https://example.com/dl/plamod-off.jpg',
                            view_url: 'https://example.com/view/plamod-off.jpg',
                        },
                    ],
                },
            },
        });

        (api.put as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true } });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-sort-1',
                productSku: 'SKU-SORT-1',
                productName: 'Grid Name Sort',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const btn = wrapper.findAll('button').find((b) => b.text().includes('Sort exporting by source')) as any;
        expect(btn).toBeTruthy();
        await btn.trigger('click');
        await Promise.resolve();
        await wrapper.vm.$nextTick();

        expect(api.put).toHaveBeenCalledWith('/api/v1/products/p-sort-1/assets/order', {
            asset_ids: [3, 2, 4, 1, 5],
        });
    });

    it('can hide/show images by source (affects grid + preview)', async () => {
        (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            data: {
                data: {
                    preferred_description_source: null,
                    contents: [],
                    assets: [
                        {
                            id: 1,
                            source: 'hlj',
                            kind: 'image',
                            filename: 'hlj-1.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'a'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 1,
                            download_url: 'https://example.com/dl/hlj-1.jpg',
                            view_url: 'https://example.com/view/hlj-1.jpg',
                        },
                        {
                            id: 2,
                            source: 'gundamplanet',
                            kind: 'image',
                            filename: 'gp-1.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'b'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 2,
                            download_url: 'https://example.com/dl/gp-1.jpg',
                            view_url: 'https://example.com/view/gp-1.jpg',
                        },
                    ],
                },
            },
        });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-1',
                productSku: 'SKU-1',
                productName: 'Grid Name 2',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        // Persisted hidden sources should start empty in this test.
        window.localStorage.clear();

        // Starts with first image visible as preview.
        const preview = wrapper.find('img.object-contain');
        expect(preview.exists()).toBe(true);
        expect(preview.attributes('src')).toContain('/view/hlj-1.jpg');

        // Hide HLJ -> preview should switch to GP.
        const hljChip = wrapper
            .findAll('button')
            .find((b) => b.text().toLowerCase().includes('hlj')) as any;
        expect(hljChip).toBeTruthy();
        await hljChip.trigger('click');
        await wrapper.vm.$nextTick();

        const preview2 = wrapper.find('img.object-contain');
        expect(preview2.attributes('src')).toContain('/view/gp-1.jpg');

        // Hiding should persist and also disable export for that source (best-effort).
        expect(window.localStorage.getItem('plamod_drawer:hidden_image_sources:p-1')).toContain('hlj');
        expect(api.patch).toHaveBeenCalledWith('/api/v1/product-assets/1/shopify-enabled', { shopify_enabled: false });

        // Show all -> HLJ image becomes visible again (count shown/total should reset).
        const showAll = wrapper.findAll('button').find((b) => b.text().includes('Show all')) as any;
        expect(showAll).toBeTruthy();
        await showAll.trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('2 shown · 2 total');
    });

    it('loads hidden sources from localStorage and hides them on open', async () => {
        window.localStorage.setItem('plamod_drawer:hidden_image_sources:p-2', JSON.stringify(['hlj']));

        (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            data: {
                data: {
                    preferred_description_source: null,
                    contents: [],
                    assets: [
                        {
                            id: 21,
                            source: 'hlj',
                            kind: 'image',
                            filename: 'hlj-1.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'a'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 1,
                            download_url: 'https://example.com/dl/hlj-1.jpg',
                            view_url: 'https://example.com/view/hlj-1.jpg',
                        },
                        {
                            id: 22,
                            source: 'gundamplanet',
                            kind: 'image',
                            filename: 'gp-1.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'b'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 2,
                            download_url: 'https://example.com/dl/gp-1.jpg',
                            view_url: 'https://example.com/view/gp-1.jpg',
                        },
                    ],
                },
            },
        });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-2',
                productSku: 'SKU-2',
                productName: 'Grid Name 3',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        // HLJ should start hidden -> only 1 shown.
        expect(wrapper.text()).toContain('1 shown · 2 total');

        // Hidden sources should be disabled for Shopify export as well.
        expect(api.patch).toHaveBeenCalledWith('/api/v1/product-assets/21/shopify-enabled', { shopify_enabled: false });
    });

    it('disables exact duplicates by checksum and persists changes', async () => {
        (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            data: {
                data: {
                    preferred_description_source: null,
                    contents: [],
                    assets: [
                        {
                            id: 10,
                            source: 'hlj',
                            kind: 'image',
                            filename: 'a.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'c'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 1,
                            download_url: 'https://example.com/dl/a.jpg',
                            view_url: 'https://example.com/view/a.jpg',
                        },
                        {
                            id: 11,
                            source: 'bandai',
                            kind: 'image',
                            filename: 'b.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'c'.repeat(64), // duplicate of id 10
                            shopify_enabled: true,
                            sort_order: 2,
                            download_url: 'https://example.com/dl/b.jpg',
                            view_url: 'https://example.com/view/b.jpg',
                        },
                        {
                            id: 12,
                            source: 'gundamplanet',
                            kind: 'image',
                            filename: 'c.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'd'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 3,
                            download_url: 'https://example.com/dl/c.jpg',
                            view_url: 'https://example.com/view/c.jpg',
                        },
                    ],
                },
            },
        });

        (api.patch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true } });
        (api.put as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true } });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-1',
                productSku: 'SKU-1',
                productName: 'Grid Name 4',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const btn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Disable exact duplicates')) as any;
        expect(btn).toBeTruthy();
        await btn.trigger('click');
        await Promise.resolve();
        await wrapper.vm.$nextTick();

        // Should disable the second image in the checksum group (id 11).
        expect(api.patch).toHaveBeenCalledWith('/api/v1/product-assets/11/shopify-enabled', { shopify_enabled: false });
        // Should persist image order afterward.
        expect(api.put).toHaveBeenCalled();
    });

    it('does not treat thumbnail reorder drag as manual upload drop', async () => {
        (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            data: {
                data: {
                    preferred_description_source: null,
                    contents: [],
                    assets: [
                        {
                            id: 101,
                            source: 'gundamhangar',
                            kind: 'image',
                            filename: 'gh-1.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'a'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 1,
                            download_url: 'https://example.com/dl/gh-1.jpg',
                            view_url: 'https://example.com/view/gh-1.jpg',
                        },
                        {
                            id: 102,
                            source: 'gundamhangar',
                            kind: 'image',
                            filename: 'gh-2.jpg',
                            mime_type: 'image/jpeg',
                            size_bytes: 10,
                            checksum_sha256: 'b'.repeat(64),
                            shopify_enabled: true,
                            sort_order: 2,
                            download_url: 'https://example.com/dl/gh-2.jpg',
                            view_url: 'https://example.com/view/gh-2.jpg',
                        },
                    ],
                },
            },
        });

        (api.post as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true, data: { created: 1 } } });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-reorder-guard',
                productSku: 'SKU-REORDER',
                productName: 'Grid Name Reorder Guard',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const thumb = wrapper.findAll('button[draggable="true"]')[0];
        expect(thumb.exists()).toBe(true);
        await thumb.trigger('dragstart');

        const dropzone = wrapper.find('[data-testid="manual-image-dropzone"]');
        expect(dropzone.exists()).toBe(true);
        await dropzone.trigger('drop', {
            dataTransfer: {
                files: [{ name: 'ghost.png', type: 'image/png' }],
            },
        });

        expect(api.post).not.toHaveBeenCalled();
    });

    it('keeps manual description draft scoped per product', async () => {
        window.localStorage.clear();
        (api.get as unknown as ReturnType<typeof vi.fn>)
            .mockResolvedValueOnce({
                data: {
                    data: {
                        preferred_description_source: null,
                        contents: [
                            {
                                source: 'other',
                                source_url: null,
                                title: 'Other A',
                                description_html: '<p>Desc A</p>',
                                attributes: null,
                                updated_at: '2026-02-04T00:00:00Z',
                            },
                        ],
                        assets: [],
                    },
                },
            })
            .mockResolvedValueOnce({
                data: {
                    data: {
                        preferred_description_source: null,
                        contents: [
                            {
                                source: 'other',
                                source_url: null,
                                title: 'Other B',
                                description_html: '<p>Desc B</p>',
                                attributes: null,
                                updated_at: '2026-02-04T00:00:00Z',
                            },
                        ],
                        assets: [],
                    },
                },
            })
            .mockResolvedValueOnce({
                data: {
                    data: {
                        preferred_description_source: null,
                        contents: [
                            {
                                source: 'other',
                                source_url: null,
                                title: 'Other A',
                                description_html: '<p>Desc A</p>',
                                attributes: null,
                                updated_at: '2026-02-04T00:00:00Z',
                            },
                        ],
                        assets: [],
                    },
                },
            });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-draft-a',
                productSku: 'SKU-A',
                productName: 'Product A',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const textareaA = wrapper.find('[data-testid="description-editor-manual"]');
        expect((textareaA.element as HTMLTextAreaElement).value).toContain('Desc A');
        await textareaA.setValue('Custom text for A');

        await wrapper.setProps({
            productId: 'p-draft-b',
            productSku: 'SKU-B',
            productName: 'Product B',
        });
        await Promise.resolve();
        await Promise.resolve();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        const textareaB = wrapper.find('[data-testid="description-editor-manual"]');
        expect((textareaB.element as HTMLTextAreaElement).value).not.toContain('Custom text for A');

        await wrapper.setProps({
            productId: 'p-draft-a',
            productSku: 'SKU-A',
            productName: 'Product A',
        });
        await Promise.resolve();
        await Promise.resolve();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        const textareaAReturn = wrapper.find('[data-testid="description-editor-manual"]');
        expect((textareaAReturn.element as HTMLTextAreaElement).value).toContain('Custom text for A');
    });

    it('shows the product grid name in the drawer header', async () => {
        (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            data: {
                data: {
                    preferred_description_source: null,
                    contents: [
                        {
                            source: 'hlj',
                            source_url: 'https://example.com/hlj',
                            title: 'HLJ Title',
                            description_html: '<p>HLJ desc</p>',
                            attributes: null,
                            updated_at: '2026-02-04T00:00:00Z',
                        },
                    ],
                    assets: [],
                },
            },
        });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-name-1',
                productSku: 'SKU-NAME-1',
                productName: 'MG 1/100 Delta Plus',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('MG 1/100 Delta Plus');
    });

    it('persists manual description html when clicking "Use this" on manual card', async () => {
        (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: {
                data: {
                    preferred_description_source: null,
                    contents: [
                        {
                            source: 'other',
                            source_url: null,
                            title: 'Other',
                            description_html: '<p>Seed</p>',
                            attributes: null,
                            updated_at: '2026-03-16T00:00:00Z',
                        },
                    ],
                    assets: [],
                },
            },
        });
        (api.patch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true } });

        const wrapper = mount(PlamodDrawer, {
            props: {
                open: true,
                productId: 'p-manual-persist',
                productSku: 'SKU-MANUAL',
                productName: 'Manual Persist Product',
                productPrice: null,
                onClose: () => undefined,
            },
            global: {
                stubs: {
                    Teleport: true,
                },
            },
        });

        await Promise.resolve();
        await wrapper.vm.$nextTick();

        const textarea = wrapper.find('[data-testid="description-editor-manual"]');
        await textarea.setValue('Line 1\nLine 2');

        const useButtons = wrapper.findAll('button').filter((b) => b.text().includes('Use this'));
        const manualUseBtn = useButtons.at(-1);
        expect(manualUseBtn).toBeTruthy();
        await manualUseBtn!.trigger('click');
        await Promise.resolve();
        await wrapper.vm.$nextTick();

        expect(api.patch).toHaveBeenCalledWith('/api/v1/products/p-manual-persist/preferred-description-source', {
            preferred_description_source: 'other',
            manual_description_html: '<p>Line 1<br />Line 2</p>',
        });
    });
});

