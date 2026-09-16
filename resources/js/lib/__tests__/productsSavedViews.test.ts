import { beforeEach, describe, expect, it } from 'vitest';

import { defaultProductsListView } from '../productsListView';
import {
    PRODUCTS_SAVED_VIEWS_KEY,
    deleteProductsSavedView,
    loadProductsSavedViews,
    persistProductsSavedViews,
    upsertProductsSavedView,
} from '../productsSavedViews';

describe('productsSavedViews', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    it('saves, reloads, updates, and deletes a named view', () => {
        const snapshot = defaultProductsListView();
        snapshot.search = 'MG';
        snapshot.sortBy = 'sku';
        snapshot.reorderGtOne = true;

        const created = upsertProductsSavedView([], '  MG restock  ', snapshot);
        expect(created.error).toBeNull();
        expect(created.view.name).toBe('MG restock');
        persistProductsSavedViews(created.views);

        const loaded = loadProductsSavedViews();
        expect(loaded).toHaveLength(1);
        expect(loaded[0]?.snapshot.search).toBe('MG');
        expect(loaded[0]?.snapshot.sortBy).toBe('sku');
        expect(loaded[0]?.snapshot.reorderGtOne).toBe(true);

        const updatedSnapshot = defaultProductsListView();
        updatedSnapshot.selectedDepartments = ['model_kits'];
        const updated = upsertProductsSavedView(
            loaded,
            'MG restock',
            updatedSnapshot,
            loaded[0]?.id,
        );
        expect(updated.error).toBeNull();
        expect(updated.view.snapshot.selectedDepartments).toEqual(['model_kits']);

        const remaining = deleteProductsSavedView(updated.views, updated.view.id);
        expect(remaining).toEqual([]);
        persistProductsSavedViews(remaining);
        expect(window.localStorage.getItem(PRODUCTS_SAVED_VIEWS_KEY)).toBe('[]');
    });

    it('overwrites the existing view when saving the same name again', () => {
        const first = upsertProductsSavedView([], 'Need restock', defaultProductsListView());
        const nextSnapshot = defaultProductsListView();
        nextSnapshot.reorderGtOne = true;
        const second = upsertProductsSavedView(first.views, 'need restock', nextSnapshot);
        expect(second.error).toBeNull();
        expect(second.views).toHaveLength(1);
        expect(second.view.id).toBe(first.view.id);
        expect(second.view.snapshot.reorderGtOne).toBe(true);
    });

    it('rejects renaming a view onto another view’s name', () => {
        const first = upsertProductsSavedView([], 'Need restock', defaultProductsListView());
        const second = upsertProductsSavedView(first.views, 'Open POs', defaultProductsListView());
        const renamed = upsertProductsSavedView(
            second.views,
            'need restock',
            defaultProductsListView(),
            second.view.id,
        );
        expect(renamed.error).toBe('A saved view already uses that name.');
        expect(renamed.views).toHaveLength(2);
    });
});
