export type TaxonomyField =
    | 'department'
    | 'manufacturer'
    | 'franchise'
    | 'product_line'
    | 'subline'
    | 'grade'
    | 'series'
    | 'scale';

export type WorkshopFacets = Record<string, string | string[]>;

export type AccessoryKind = 'display_stand' | 'option_parts' | 'detail_parts' | 'scene_base';

export type TaxonomyValues = Record<TaxonomyField, string | null> & {
    workshop_shelf: string | null;
    workshop_facets: WorkshopFacets;
    accessory_kind: AccessoryKind | string | null;
};

export type TaxonomyEvidence = {
    value: string | null;
    source_url: string | null;
    source_label: string;
    confidence: number;
    notes: string | null;
};

export type TaxonomyEvidenceField =
    | TaxonomyField
    | 'workshop_shelf'
    | 'workshop_facets'
    | 'accessory_kind';

export type TaxonomyVerification = {
    id: string;
    status: 'proposed' | 'verified' | 'overridden';
    research_version: string;
    overall_confidence: number;
    research_method: string;
    proposed_values: TaxonomyValues;
    previous_values: TaxonomyValues;
    evidence: Partial<Record<TaxonomyEvidenceField, TaxonomyEvidence>>;
    operator_notes: string | null;
    product: TaxonomyValues & {
        id: string;
        sku: string;
        description: string;
        archived: boolean;
        published_on_shopify: boolean;
    };
};

export type TaxonomySummary = {
    total: number;
    proposed: number;
    verified: number;
    overridden: number;
    low_confidence: number;
};

export const taxonomyFields: Array<{ key: TaxonomyField; label: string }> = [
    { key: 'department', label: 'Department' },
    { key: 'manufacturer', label: 'Manufacturer' },
    { key: 'franchise', label: 'Franchise' },
    { key: 'product_line', label: 'Product line' },
    { key: 'subline', label: 'Sub-line' },
    { key: 'grade', label: 'Grade' },
    { key: 'series', label: 'Series' },
    { key: 'scale', label: 'Scale' },
];

export const workshopTaxonomyFields: Array<{
    key: 'workshop_shelf' | 'workshop_facets';
    label: string;
}> = [
    { key: 'workshop_shelf', label: 'T&S shelf' },
    { key: 'workshop_facets', label: 'Facets' },
];

export const accessoryKindLabels: Record<AccessoryKind, string> = {
    display_stand: 'Display stand',
    option_parts: 'Option parts',
    detail_parts: 'Detail parts',
    scene_base: 'Scene base',
};

export function formatAccessoryKind(kind: string | null | undefined): string {
    if (!kind || kind.trim() === '') {
        return '—';
    }

    return accessoryKindLabels[kind as AccessoryKind] ?? kind;
}

export function emptyTaxonomyValues(): TaxonomyValues {
    return {
        department: null,
        manufacturer: null,
        franchise: null,
        product_line: null,
        subline: null,
        grade: null,
        series: null,
        scale: null,
        workshop_shelf: null,
        workshop_facets: {},
        accessory_kind: null,
    };
}

export function formatWorkshopFacets(facets: WorkshopFacets | null | undefined): string {
    if (!facets || Object.keys(facets).length === 0) {
        return '—';
    }

    return Object.entries(facets)
        .map(([key, value]) => {
            const rendered = Array.isArray(value) ? value.join(', ') : value;
            return `${key}: ${rendered}`;
        })
        .join(' · ');
}

export function normalizeTaxonomyValues(raw: Partial<TaxonomyValues> | null | undefined): TaxonomyValues {
    const base = emptyTaxonomyValues();
    if (!raw) {
        return base;
    }

    for (const field of taxonomyFields) {
        const value = raw[field.key];
        base[field.key] = typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
    }

    base.workshop_shelf =
        typeof raw.workshop_shelf === 'string' && raw.workshop_shelf.trim() !== ''
            ? raw.workshop_shelf.trim()
            : null;
    base.workshop_facets =
        raw.workshop_facets && typeof raw.workshop_facets === 'object' ? raw.workshop_facets : {};
    base.accessory_kind =
        typeof raw.accessory_kind === 'string' && raw.accessory_kind.trim() !== ''
            ? raw.accessory_kind.trim()
            : null;

    return base;
}
