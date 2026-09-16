import { api } from './api';
import {
    clearProductsSavedViews,
    loadProductsSavedViews,
    localSavedViewsMissingFromShared,
    parseProductsSavedViewFromApi,
    productSavedViewWritePayload,
    sortProductsSavedViews,
    type ProductsSavedView,
} from './productsSavedViews';
import type { ProductsListViewSnapshot } from './productsListView';

type SavedViewCollectionResponse = {
    data: unknown[];
};

type SavedViewItemResponse = {
    data: unknown;
};

function viewsFromCollection(payload: SavedViewCollectionResponse): ProductsSavedView[] {
    const views: ProductsSavedView[] = [];
    for (const item of payload.data) {
        const parsed = parseProductsSavedViewFromApi(item);
        if (parsed) views.push(parsed);
    }
    return sortProductsSavedViews(views);
}

function viewFromItem(payload: SavedViewItemResponse): ProductsSavedView {
    const parsed = parseProductsSavedViewFromApi(payload.data);
    if (!parsed) {
        throw new Error('Saved view response was invalid.');
    }
    return parsed;
}

export async function listProductSavedViews(): Promise<ProductsSavedView[]> {
    const { data } = await api.get<SavedViewCollectionResponse>('/api/v1/products/saved-views');
    return viewsFromCollection(data);
}

export async function upsertProductSavedViewRemote(
    name: string,
    snapshot: ProductsListViewSnapshot,
    visibleColumns: readonly string[],
): Promise<ProductsSavedView> {
    const { data } = await api.post<SavedViewItemResponse>(
        '/api/v1/products/saved-views',
        productSavedViewWritePayload(name, snapshot, visibleColumns),
    );
    return viewFromItem(data);
}

export async function updateProductSavedViewRemote(
    id: string,
    name: string,
    snapshot: ProductsListViewSnapshot,
    visibleColumns: readonly string[],
): Promise<ProductsSavedView> {
    const { data } = await api.put<SavedViewItemResponse>(
        `/api/v1/products/saved-views/${id}`,
        productSavedViewWritePayload(name, snapshot, visibleColumns),
    );
    return viewFromItem(data);
}

export async function deleteProductSavedViewRemote(id: string): Promise<void> {
    await api.delete(`/api/v1/products/saved-views/${id}`);
}

export async function importLocalProductSavedViews(
    shared: ProductsSavedView[],
): Promise<ProductsSavedView[]> {
    const missing = localSavedViewsMissingFromShared(shared, loadProductsSavedViews());
    for (const view of missing) {
        await upsertProductSavedViewRemote(view.name, view.snapshot, view.visibleColumns);
    }
    clearProductsSavedViews();
    if (missing.length === 0) {
        return shared;
    }
    return listProductSavedViews();
}
