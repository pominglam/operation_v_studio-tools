export type ShopifyProductPushOptions = {
    info: boolean;
    images: boolean;
    quantities: boolean;
    price: boolean;
    publish_status: boolean;
    sales_channels: boolean;
};

export type BulkPushShopifySkipReason =
    | 'missing_sku'
    | 'missing_selling_price'
    | 'missing_shopify_mirror'
    | 'create_requires_info'
    | 'create_requires_price'
    | 'missing_inventory_location'
    | 'no_fields_selected'
    | null;

export type BulkPushShopifyPreviewRow = {
    product_uuid: string;
    sku: string;
    description: string;
    handle: string | null;
    erp_available_qty: number;
    erp_hold_qty: number;
    shopify_push_qty: number;
    shopify_available_qty: number | null;
    selling_price: string | null;
    has_selling_price: boolean;
    published_on_shopify: boolean;
    push_action: 'create' | 'update';
    option_independent_skip: BulkPushShopifySkipReason;
    push_eligible: boolean;
    skip_reason: BulkPushShopifySkipReason;
};

export type BulkPushShopifyPreviewBase = {
    location_gid: string;
    location_name: string | null;
    write_products_scope_ok: boolean;
    write_inventory_scope_ok: boolean;
    write_publications_scope_ok: boolean;
    images_enabled: boolean;
    tunnel_url: string | null;
    products: BulkPushShopifyPreviewRow[];
};

export type BulkPushShopifyPreview = BulkPushShopifyPreviewBase & {
    push_options: ShopifyProductPushOptions;
    push_count: number;
    create_count: number;
    update_count: number;
    skip_count: number;
    product_uuids: string[];
};

export function hasAnyPushOption(options: ShopifyProductPushOptions): boolean {
    return (
        options.info ||
        options.images ||
        options.quantities ||
        options.price ||
        options.publish_status ||
        options.sales_channels
    );
}

export function resolveSkipReasonForRow(
    row: Pick<
        BulkPushShopifyPreviewRow,
        'push_action' | 'has_selling_price' | 'option_independent_skip'
    >,
    options: ShopifyProductPushOptions,
    locationGid: string,
): BulkPushShopifySkipReason {
    if (row.option_independent_skip) {
        return row.option_independent_skip;
    }

    if (!hasAnyPushOption(options)) {
        return 'no_fields_selected';
    }

    if (row.push_action === 'update') {
        if (options.price && !row.has_selling_price) {
            return 'missing_selling_price';
        }
        if (options.quantities && locationGid === '') {
            return 'missing_inventory_location';
        }

        return null;
    }

    if (!options.info) {
        return 'create_requires_info';
    }
    if (!options.price) {
        return 'create_requires_price';
    }
    if (!row.has_selling_price) {
        return 'missing_selling_price';
    }
    if (options.quantities && locationGid === '') {
        return 'missing_inventory_location';
    }

    return null;
}

export function applyPushOptionsToPreview(
    base: BulkPushShopifyPreviewBase,
    options: ShopifyProductPushOptions,
): BulkPushShopifyPreview {
    const products = base.products.map((row) => {
        const skipReason = resolveSkipReasonForRow(row, options, base.location_gid);

        return {
            ...row,
            push_eligible: skipReason === null,
            skip_reason: skipReason,
        };
    });

    const productUuids: string[] = [];
    let createCount = 0;
    let updateCount = 0;

    for (const row of products) {
        if (!row.push_eligible) {
            continue;
        }
        productUuids.push(row.product_uuid);
        if (row.push_action === 'create') {
            createCount++;
        } else {
            updateCount++;
        }
    }

    return {
        ...base,
        push_options: { ...options },
        products,
        push_count: productUuids.length,
        create_count: createCount,
        update_count: updateCount,
        skip_count: products.length - productUuids.length,
        product_uuids: productUuids,
    };
}
