export function storePreorderManualSku(name: string): string {
    const cleaned = name
        .replace(/\((?:pre-?order|po)\)/gi, ' ')
        .replace(/\bpre-?orders?\b/gi, ' ')
        .replace(/\bmodel kits?\b/gi, ' ')
        .replace(/\beta\b[:\s-]*[a-z]{3,9}\.?\s*\d{4}/gi, ' ')
        .replace(/[\/\\]/g, '-');
    const slug = cleaned
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .toLowerCase();
    const base = slug === '' ? 'kit' : slug;
    let sku = `OVS-${base}`;
    if (sku.length > 64) {
        sku = sku.slice(0, 64).replace(/-+$/g, '');
    }

    return sku;
}
