export function mergeVendorOption(list: string[], vendor: string | null | undefined): string[] {
    const trimmed = (vendor ?? '').trim();
    if (trimmed === '') {
        return list;
    }
    if (list.includes(trimmed)) {
        return list;
    }

    return [...list, trimmed].sort((a, b) => a.localeCompare(b));
}

export function vendorChoicesIncludingDraft(options: string[], draft: string): string[] {
    const base = options.map((v) => v.trim()).filter((v) => v !== '');
    const cur = draft.trim();
    const merged = cur !== '' ? [...base, cur] : base;

    return Array.from(new Set(merged)).sort((a, b) => a.localeCompare(b));
}
