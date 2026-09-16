export type ProductInfoAsset = {
    id: number;
    source: string;
    kind: string;
    filename: string;
    mime_type: string | null;
    size_bytes: number | null;
    origin_url?: string | null;
    origin_width?: number | null;
    origin_height?: number | null;
    checksum_sha256?: string | null;
    sort_order?: number | null;
    shopify_enabled?: boolean | null;
    download_url: string;
    view_url: string;
    thumb_url?: string | null;
};

export type SourceKey =
    | 'bandai'
    | 'hlj'
    | 'gundamplanet'
    | 'newtype'
    | 'gundamhangar'
    | 'plamod'
    | 'manual_upload'
    | 'other';

export const SOURCE_LABELS: Record<SourceKey, string> = {
    bandai: 'Bandai',
    hlj: 'HLJ',
    plamod: 'Plamod',
    gundamplanet: 'GundamPlanet',
    newtype: 'Newtype',
    gundamhangar: 'GundamHangar',
    manual_upload: 'Manual upload',
    other: 'Other',
};

export const SOURCE_BADGE_CLASSES: Record<SourceKey, string> = {
    plamod: 'border-indigo-700 bg-indigo-600 text-white',
    hlj: 'border-emerald-700 bg-emerald-600 text-white',
    newtype: 'border-fuchsia-700 bg-fuchsia-600 text-white',
    gundamplanet: 'border-amber-700 bg-amber-500 text-slate-950',
    gundamhangar: 'border-teal-700 bg-teal-600 text-white',
    bandai: 'border-sky-700 bg-sky-600 text-white',
    manual_upload: 'border-rose-700 bg-rose-600 text-white',
    other: 'border-slate-700 bg-slate-700 text-white',
};

export function sourceBadgeClass(key: SourceKey): string {
    return SOURCE_BADGE_CLASSES[key] ?? SOURCE_BADGE_CLASSES.other;
}

export function normalizeSourceKey(source: string): SourceKey {
    const s = source.trim().toLowerCase();
    if (s === 'bandai') return 'bandai';
    if (s === 'hlj') return 'hlj';
    if (s === 'plamod') return 'plamod';
    if (s === 'gundamplanet') return 'gundamplanet';
    if (s === 'newtype') return 'newtype';
    if (s === 'gundamhangar') return 'gundamhangar';
    if (s === 'manual_upload') return 'manual_upload';
    return 'other';
}

export function isImage(a: ProductInfoAsset): boolean {
    if (a.kind === 'image') return true;
    return (a.mime_type ?? '').startsWith('image/');
}

export function isExporting(a: ProductInfoAsset): boolean {
    return (a.shopify_enabled ?? true) === true;
}

export function isManualUploadAsset(a: ProductInfoAsset | null): boolean {
    return a !== null && normalizeSourceKey(a.source) === 'manual_upload';
}

export function assetThumbUrl(asset: ProductInfoAsset): string {
    return asset.thumb_url ?? asset.view_url;
}
