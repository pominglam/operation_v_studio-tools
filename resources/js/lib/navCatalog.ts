export type NavRouteContext = {
    path: string;
    routeName: string | symbol | null | undefined;
};

export type NavChildLink = {
    id: string;
    label: string;
    path: string;
    isActive: (ctx: NavRouteContext) => boolean;
};

export type NavGroup = {
    kind: 'group';
    id: string;
    label: string;
    children: NavChildLink[];
    isActive: (ctx: NavRouteContext) => boolean;
};

export type NavLink = {
    kind: 'link';
    id: string;
    label: string;
    path: string;
    isActive: (ctx: NavRouteContext) => boolean;
};

export type NavEntry = NavGroup | NavLink;

function pathStartsWith(prefix: string) {
    return ({ path }: NavRouteContext): boolean => path.startsWith(prefix);
}

function routeIs(name: string) {
    return ({ routeName }: NavRouteContext): boolean => routeName === name;
}

function anyActive(
    ctx: NavRouteContext,
    matchers: Array<(ctx: NavRouteContext) => boolean>,
): boolean {
    return matchers.some((match) => match(ctx));
}

/** Admin top navigation — grouped to limit horizontal sprawl. */
export const ADMIN_NAV_ENTRIES: NavEntry[] = [
    {
        kind: 'group',
        id: 'catalog',
        label: 'Catalog',
        isActive: (ctx) =>
            anyActive(ctx, [
                routeIs('products'),
                routeIs('store-preorders'),
                routeIs('product-taxonomy'),
                pathStartsWith('/inventory-check'),
                pathStartsWith('/price-research'),
            ]),
        children: [
            {
                id: 'products',
                label: 'Products',
                path: '/products',
                isActive: routeIs('products'),
            },
            {
                id: 'store-preorders',
                label: 'Store preorders',
                path: '/store-preorders',
                isActive: routeIs('store-preorders'),
            },
            {
                id: 'product-taxonomy',
                label: 'Taxonomy',
                path: '/products/taxonomy',
                isActive: routeIs('product-taxonomy'),
            },
            {
                id: 'inventory-check',
                label: 'Inventory Check',
                path: '/inventory-check',
                isActive: pathStartsWith('/inventory-check'),
            },
            {
                id: 'price-research',
                label: 'Pricing',
                path: '/price-research',
                isActive: pathStartsWith('/price-research'),
            },
        ],
    },
    {
        kind: 'group',
        id: 'procurement',
        label: 'Procurement',
        isActive: (ctx) =>
            anyActive(ctx, [
                pathStartsWith('/purchase-orders'),
                pathStartsWith('/special-orders'),
                routeIs('preorders'),
                pathStartsWith('/restocking/plamod'),
            ]),
        children: [
            {
                id: 'purchase-orders',
                label: 'Purchase Orders',
                path: '/purchase-orders',
                isActive: pathStartsWith('/purchase-orders'),
            },
            {
                id: 'special-orders',
                label: 'Special Order',
                path: '/special-orders',
                isActive: pathStartsWith('/special-orders'),
            },
            {
                id: 'preorders',
                label: 'Plamod preorders',
                path: '/preorders',
                isActive: routeIs('preorders'),
            },
            {
                id: 'plamod-restock',
                label: 'Plamod Restock',
                path: '/restocking/plamod',
                isActive: pathStartsWith('/restocking/plamod'),
            },
        ],
    },
    {
        kind: 'link',
        id: 'shopify-orders',
        label: 'Sales',
        path: '/orders',
        isActive: pathStartsWith('/orders'),
    },
    {
        kind: 'group',
        id: 'events',
        label: 'Events',
        isActive: (ctx) =>
            anyActive(ctx, [
                routeIs('store-events'),
                routeIs('marketing-notes'),
                routeIs('tcg-events'),
            ]),
        children: [
            {
                id: 'store-events',
                label: 'Store events',
                path: '/store-events',
                isActive: routeIs('store-events'),
            },
            {
                id: 'marketing-notes',
                label: 'Marketing notes',
                path: '/marketing-notes',
                isActive: routeIs('marketing-notes'),
            },
            {
                id: 'tcg-events',
                label: 'TCG Events',
                path: '/tcg-events',
                isActive: routeIs('tcg-events'),
            },
        ],
    },
    {
        kind: 'link',
        id: 'reports',
        label: 'Reports',
        path: '/reports/staff-orders',
        isActive: pathStartsWith('/reports'),
    },
    {
        kind: 'group',
        id: 'system',
        label: 'System',
        isActive: (ctx) =>
            anyActive(ctx, [
                routeIs('maintenance'),
                routeIs('sync-progress'),
                routeIs('shopify-webhooks'),
            ]),
        children: [
            {
                id: 'maintenance',
                label: 'Maintenance',
                path: '/maintenance',
                isActive: routeIs('maintenance'),
            },
            {
                id: 'sync-progress',
                label: 'Sync progress',
                path: '/sync-progress',
                isActive: routeIs('sync-progress'),
            },
            {
                id: 'shopify-webhooks',
                label: 'Webhook logs',
                path: '/shopify/webhooks',
                isActive: routeIs('shopify-webhooks'),
            },
        ],
    },
];

export function navRouteContext(
    path: string,
    routeName: string | symbol | null | undefined,
): NavRouteContext {
    return { path, routeName };
}
