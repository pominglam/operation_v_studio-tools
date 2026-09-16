import { clearPageState, loadPageState, savePageState } from './pageState';
import {
    cloneProductsListView,
    parseProductsListViewSnapshot,
    type ProductsListViewSnapshot,
} from './productsListView';
import {
    defaultProductsTableVisibleColumns,
    parseProductsTableVisibleColumns,
    productsTableColumnsEqual,
    type ProductsTableColumnKey,
} from './productsTableColumns';

export const PRODUCTS_SAVED_VIEWS_KEY = 'products:saved-views:v1';
export const PRODUCTS_SAVED_VIEW_NAME_MAX = 40;

export type ProductsSavedView = {
    id: string;
    name: string;
    createdAt: string;
    updatedAt: string;
    snapshot: ProductsListViewSnapshot;
    visibleColumns: ProductsTableColumnKey[];
};

export function normalizeProductsSavedViewName(raw: string): string {
    return raw.trim().replace(/\s+/g, ' ').slice(0, PRODUCTS_SAVED_VIEW_NAME_MAX);
}

export function loadProductsSavedViews(): ProductsSavedView[] {
    const raw = loadPageState<unknown>(PRODUCTS_SAVED_VIEWS_KEY);
    if (!Array.isArray(raw)) return [];

    const views: ProductsSavedView[] = [];
    for (const item of raw) {
        const parsed = parseProductsSavedView(item);
        if (parsed) views.push(parsed);
    }
    return sortProductsSavedViews(views);
}

export function sortProductsSavedViews(views: ProductsSavedView[]): ProductsSavedView[] {
    return [...views].sort((a, b) =>
        a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }),
    );
}

export type ProductSavedViewApiRow = {
    id: string;
    name: string;
    created_at?: string | null;
    updated_at?: string | null;
    snapshot: unknown;
    visible_columns?: unknown;
};

export function parseProductsSavedViewFromApi(raw: unknown): ProductsSavedView | null {
    if (raw === null || typeof raw !== 'object') return null;
    const item = raw as ProductSavedViewApiRow;

    return parseProductsSavedView({
        id: item.id,
        name: item.name,
        createdAt: typeof item.created_at === 'string' ? item.created_at : '',
        updatedAt: typeof item.updated_at === 'string' ? item.updated_at : '',
        snapshot: item.snapshot,
        visibleColumns: item.visible_columns,
    });
}

export function productSavedViewWritePayload(
    name: string,
    snapshot: ProductsListViewSnapshot,
    visibleColumns: readonly string[],
): {
    name: string;
    snapshot: ProductsListViewSnapshot;
    visible_columns: ProductsTableColumnKey[];
} {
    return {
        name: normalizeProductsSavedViewName(name),
        snapshot: cloneProductsListView(snapshot),
        visible_columns: parseProductsTableVisibleColumns(visibleColumns),
    };
}

export function localSavedViewsMissingFromShared(
    shared: ProductsSavedView[],
    local: ProductsSavedView[],
): ProductsSavedView[] {
    const names = new Set(shared.map((view) => view.name.toLowerCase()));
    return local.filter((view) => !names.has(view.name.toLowerCase()));
}

export function persistProductsSavedViews(views: ProductsSavedView[]): void {
    savePageState(PRODUCTS_SAVED_VIEWS_KEY, views);
}

export function clearProductsSavedViews(): void {
    clearPageState(PRODUCTS_SAVED_VIEWS_KEY);
}

export function upsertProductsSavedView(
    views: ProductsSavedView[],
    name: string,
    snapshot: ProductsListViewSnapshot,
    id?: string,
    visibleColumns: readonly string[] = defaultProductsTableVisibleColumns(),
): { views: ProductsSavedView[]; view: ProductsSavedView; error: string | null } {
    const normalized = normalizeProductsSavedViewName(name);
    if (normalized === '') {
        return { views, view: views[0] ?? emptyView(snapshot), error: 'Name is required.' };
    }

    const now = new Date().toISOString();
    const sameName = views.find((view) => view.name.toLowerCase() === normalized.toLowerCase());
    const targetId = id ?? sameName?.id;
    if (sameName && targetId && sameName.id !== targetId) {
        return { views, view: sameName, error: 'A saved view already uses that name.' };
    }

    const existing = targetId ? views.find((view) => view.id === targetId) : undefined;
    const view: ProductsSavedView = {
        id: existing?.id ?? newSavedViewId(),
        name: normalized,
        createdAt: existing?.createdAt ?? now,
        updatedAt: now,
        snapshot: cloneProductsListView(snapshot),
        visibleColumns: parseProductsTableVisibleColumns(visibleColumns),
    };

    const next = existing
        ? views.map((item) => (item.id === view.id ? view : item))
        : [...views, view];

    return {
        views: sortProductsSavedViews(next),
        view,
        error: null,
    };
}

export function deleteProductsSavedView(
    views: ProductsSavedView[],
    id: string,
): ProductsSavedView[] {
    return views.filter((view) => view.id !== id);
}

export function findMatchingProductsSavedView(
    views: ProductsSavedView[],
    snapshot: ProductsListViewSnapshot,
    equals: (left: ProductsListViewSnapshot, right: ProductsListViewSnapshot) => boolean,
    visibleColumns?: readonly string[],
): ProductsSavedView | null {
    return (
        views.find((view) => {
            if (!equals(view.snapshot, snapshot)) {
                return false;
            }
            if (visibleColumns === undefined) {
                return true;
            }
            return productsTableColumnsEqual(view.visibleColumns, visibleColumns);
        }) ?? null
    );
}

export function parseProductsSavedView(raw: unknown): ProductsSavedView | null {
    if (raw === null || typeof raw !== 'object') return null;
    const item = raw as Partial<ProductsSavedView>;
    const snapshot = parseProductsListViewSnapshot(item.snapshot);
    if (!snapshot) return null;
    if (typeof item.id !== 'string' || item.id.trim() === '') return null;
    const name = normalizeProductsSavedViewName(typeof item.name === 'string' ? item.name : '');
    if (name === '') return null;

    return {
        id: item.id,
        name,
        createdAt: typeof item.createdAt === 'string' ? item.createdAt : new Date().toISOString(),
        updatedAt: typeof item.updatedAt === 'string' ? item.updatedAt : new Date().toISOString(),
        snapshot,
        visibleColumns: parseProductsTableVisibleColumns(item.visibleColumns),
    };
}

function newSavedViewId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `view-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function emptyView(snapshot: ProductsListViewSnapshot): ProductsSavedView {
    return {
        id: '',
        name: '',
        createdAt: '',
        updatedAt: '',
        snapshot: cloneProductsListView(snapshot),
        visibleColumns: defaultProductsTableVisibleColumns(),
    };
}
