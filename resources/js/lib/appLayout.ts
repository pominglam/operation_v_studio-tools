export function isFullWidthAppPath(path: string): boolean {
    if (path.startsWith('/price-research')) return true;
    if (path === '/products') return true;
    if (path.startsWith('/purchase-orders/')) return true;
    return false;
}
