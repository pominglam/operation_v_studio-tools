import { isPurchaseOrderBetaPath } from './purchaseOrderBeta';

export function isFullWidthAppPath(path: string): boolean {
    if (path.startsWith('/price-research')) return true;
    if (path === '/products' || path.startsWith('/products/taxonomy')) return true;
    if (path.startsWith('/purchase-orders/')) return true;
    return false;
}

export function isStandaloneAppChromePath(path: string): boolean {
    return isPurchaseOrderBetaPath(path);
}
