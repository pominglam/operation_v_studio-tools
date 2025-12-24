export function normalizeAsciiDigits(input: string): string {
    // Convert full-width digits (０-９) to ASCII digits (0-9)
    return input.replace(/[０-９]/g, (d) => String(d.charCodeAt(0) - 0xff10));
}

export function parseNonNegativeIntOrNull(input: string, max = 2147483647): number | null {
    const normalized = normalizeAsciiDigits(input);
    const trimmed = normalized.trim();
    if (trimmed === '') return null;

    // Allow common thousands separators/spaces from pastes
    const cleaned = trimmed.replace(/[, _]/g, '');

    if (!/^\d+$/.test(cleaned)) {
        throw new Error('invalid');
    }

    const n = Number.parseInt(cleaned, 10);
    if (!Number.isSafeInteger(n) || n < 0 || n > max) {
        throw new Error('invalid');
    }

    return n;
}


