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

/** Inverse of a vendor→CAD rate: foreign currency units per 1 CAD (e.g. 4.891 RMB). */
export function formatFxCadToForeign(
    rateToCad: string | number | null | undefined,
    decimals = 4,
): string {
    const rate = parseMoney(rateToCad);
    if (rate === null || rate <= 0) {
        return '';
    }

    const inverse = 1 / rate;

    return inverse.toFixed(decimals).replace(/\.?0+$/, '');
}

export function formatFxCadToForeignLabel(
    rateToCad: string | number | null | undefined,
    currencyLabel: string | null | undefined,
): string {
    const currency = currencyLabel?.trim() || '';
    if (currency === '' || currency === 'CAD') {
        return currency === 'CAD' ? '1 CAD' : '';
    }

    const foreignPerCad = formatFxCadToForeign(rateToCad);
    if (foreignPerCad === '') {
        return '';
    }

    return `${foreignPerCad} ${currency} per 1 CAD`;
}


