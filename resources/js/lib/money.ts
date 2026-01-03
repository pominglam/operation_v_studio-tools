export function parseMoney(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined) return null;
    const s = typeof value === 'number' ? String(value) : String(value);
    const cleaned = s.replace(/[^0-9.-]/g, '');
    if (!cleaned) return null;
    const n = Number.parseFloat(cleaned);
    return Number.isFinite(n) ? n : null;
}

export function formatMoney2(value: string | number | null | undefined): string {
    const n = parseMoney(value);
    if (n === null) return '—';
    return n.toFixed(2);
}

export function formatMoney2OrEmpty(value: string | number | null | undefined): string {
    const n = parseMoney(value);
    if (n === null) return '';
    return n.toFixed(2);
}

export function formatMoney2OrOriginal(value: string | number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    const n = parseMoney(value);
    if (n === null) return String(value);
    return n.toFixed(2);
}


