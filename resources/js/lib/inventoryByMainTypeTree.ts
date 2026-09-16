import {
    compareInventoryByMainTypeRows,
    sumInventoryRows,
    type InventoryByMainTypeReportRow,
    type InventoryByMainTypeReportTotals,
    type InventoryByMainTypeSortKey,
} from './inventoryByMainTypeReport';

export type InventoryTaxonomyTreeNode = {
    key: string;
    label: string;
    depth: 0 | 1 | 2;
    totals: InventoryByMainTypeReportTotals;
    children: InventoryTaxonomyTreeNode[];
    drillDown: InventoryTaxonomyDrillDown;
};

export type InventoryTaxonomyDrillDown = {
    departments: string[];
    productLines: string[];
    workshopShelves: string[];
    grades: string[];
    sublines: string[];
    types: string[];
};

const UNSET_LABEL = '(unset)';

const TS_DEPARTMENTS = ['tools', 'supplies', 'paints'] as const;

const MEGA_SECTION_ORDER: Readonly<Record<string, number>> = {
    'model-kits': 10,
    'tools-supplies': 20,
    miscellaneous: 30,
    other: 90,
};

type Placement = {
    section: 'model-kits' | 'tools-supplies' | 'miscellaneous' | 'other';
    midKey: string;
    midLabel: string;
    midOrder: number;
    leafKey: string | null;
    leafLabel: string | null;
    leafOrder: number;
    midKind: NodeKind;
    leafKind: NodeKind | null;
};

type NodeKind =
    | 'section'
    | 'mk-grade'
    | 'mk-subline'
    | 'mk-line'
    | 'mk-type'
    | 'ts-job'
    | 'ts-shelf'
    | 'misc-line'
    | 'other';

type DrillDownFields = {
    department?: boolean;
    product_line?: boolean;
    workshop_shelf?: boolean;
    grade?: boolean;
    subline?: boolean;
    type?: boolean;
};

export const DEFAULT_INVENTORY_TREE_EXPANDED = new Set([
    'model-kits',
    'tools-supplies',
    'miscellaneous',
    'other',
]);

export function buildInventoryTaxonomyTree(
    rows: InventoryByMainTypeReportRow[],
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryTaxonomyTreeNode[] {
    const buckets = new Map<string, Map<string, MidBucket>>();

    for (const row of rows) {
        const placement = placeRow(row);
        const sectionBuckets = buckets.get(placement.section) ?? new Map<string, MidBucket>();
        const mid = sectionBuckets.get(placement.midKey) ?? {
            key: placement.midKey,
            label: placement.midLabel,
            order: placement.midOrder,
            kind: placement.midKind,
            rows: [],
            leaves: new Map<string, LeafBucket>(),
        };
        mid.rows.push(row);
        if (
            placement.leafKey !== null &&
            placement.leafLabel !== null &&
            placement.leafKind !== null
        ) {
            const leaf = mid.leaves.get(placement.leafKey) ?? {
                key: placement.leafKey,
                label: placement.leafLabel,
                order: placement.leafOrder,
                kind: placement.leafKind,
                rows: [],
            };
            leaf.rows.push(row);
            mid.leaves.set(placement.leafKey, leaf);
        }
        sectionBuckets.set(placement.midKey, mid);
        buckets.set(placement.section, sectionBuckets);
    }

    const tree = [
        buildSectionNode(
            'model-kits',
            'Model kits',
            buckets.get('model-kits'),
            { department: true },
            sortBy,
            sortDir,
        ),
        buildSectionNode(
            'tools-supplies',
            'Tools & Supplies',
            buckets.get('tools-supplies'),
            { department: true, workshop_shelf: true },
            sortBy,
            sortDir,
        ),
        buildSectionNode(
            'miscellaneous',
            'Miscellaneous',
            buckets.get('miscellaneous'),
            { department: true, product_line: true },
            sortBy,
            sortDir,
        ),
        buildSectionNode(
            'other',
            'Other',
            buckets.get('other'),
            { department: true },
            sortBy,
            sortDir,
        ),
    ].filter((section) => section.totals.catalog_skus > 0);

    return sortSectionNodes(tree, sortBy, sortDir);
}

type MidBucket = {
    key: string;
    label: string;
    order: number;
    kind: NodeKind;
    rows: InventoryByMainTypeReportRow[];
    leaves: Map<string, LeafBucket>;
};

type LeafBucket = {
    key: string;
    label: string;
    order: number;
    kind: NodeKind;
    rows: InventoryByMainTypeReportRow[];
};

function buildSectionNode(
    key: string,
    label: string,
    mids: Map<string, MidBucket> | undefined,
    sectionInclude: DrillDownFields,
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryTaxonomyTreeNode {
    const midList = [...(mids?.values() ?? [])].sort((left, right) => left.order - right.order);
    const rows = midList.flatMap((mid) => mid.rows);
    const children = sortMidNodes(
        midList.map((mid) => buildMidNode(key, mid, sortBy, sortDir)),
        sortBy,
        sortDir,
    );

    return {
        key,
        label,
        depth: 0,
        totals: sumInventoryRows(rows),
        children,
        drillDown: drillDownFromRows(rows, sectionInclude),
    };
}

function buildMidNode(
    sectionKey: string,
    mid: MidBucket,
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryTaxonomyTreeNode {
    const leafList = [...mid.leaves.values()].sort((left, right) => left.order - right.order);
    const shouldNestLeaves = leafList.length > 1;
    const children = shouldNestLeaves
        ? sortLeafNodes(
              leafList.map((leaf) =>
                  leafNode(
                      `${sectionKey}:${mid.key}:${leaf.key}`,
                      leaf.label,
                      leaf.rows,
                      leaf.kind,
                  ),
              ),
              sortBy,
              sortDir,
          )
        : [];

    return {
        key: `${sectionKey}:${mid.key}`,
        label: mid.label,
        depth: 1,
        totals: sumInventoryRows(mid.rows),
        children,
        drillDown: drillDownFromRows(mid.rows, includeFor(mid.kind)),
    };
}

function leafNode(
    key: string,
    label: string,
    rows: InventoryByMainTypeReportRow[],
    kind: NodeKind,
): InventoryTaxonomyTreeNode {
    return {
        key,
        label,
        depth: 2,
        totals: sumInventoryRows(rows),
        children: [],
        drillDown: drillDownFromRows(rows, includeFor(kind)),
    };
}

function includeFor(kind: NodeKind): DrillDownFields {
    switch (kind) {
        case 'mk-grade':
            return { department: true, grade: true };
        case 'mk-subline':
            return { department: true, grade: true, subline: true };
        case 'mk-line':
            return { department: true, product_line: true };
        case 'mk-type':
            return { department: true, type: true };
        case 'ts-job':
        case 'ts-shelf':
            return { department: true, workshop_shelf: true };
        case 'misc-line':
            return { department: true, product_line: true };
        case 'other':
        case 'section':
            return { department: true };
    }
}

function placeRow(row: InventoryByMainTypeReportRow): Placement {
    const department = departmentKey(row);

    if (TS_DEPARTMENTS.includes(department as (typeof TS_DEPARTMENTS)[number])) {
        return placeToolsRow(row);
    }

    if (isKeychain(row)) {
        return miscPlacement('keychains', 'Keychains', 10, row);
    }

    if (isCcsToys(row)) {
        return miscPlacement('ccs-toys', 'CCS Toys', 20, row);
    }

    if (isActionBase(row) || isOptionParts(row) || isModelKitShelf(row) || isThirtyMinutes(row)) {
        return placeModelKitRow(row);
    }

    if (department === 'figures' || department === 'accessories' || department === 'misc') {
        const line = fieldValue(row, 'product_line');

        return miscPlacement(
            line === '' ? 'other-products' : `line:${normKey(line)}`,
            line === '' ? 'Other products' : line,
            line === '' ? 90 : 50,
            row,
        );
    }

    return {
        section: 'other',
        midKey: department === '' ? 'uncategorized' : `dept:${department}`,
        midLabel:
            department === '' ? 'Uncategorized' : fieldValue(row, 'department') || row.main_type,
        midOrder: 10,
        leafKey: null,
        leafLabel: null,
        leafOrder: 0,
        midKind: 'other',
        leafKind: null,
    };
}

function placeModelKitRow(row: InventoryByMainTypeReportRow): Placement {
    if (isActionBase(row)) {
        return mkLine('action-bases', 'Action bases', 80, row);
    }

    if (isOptionParts(row)) {
        return mkLine('option-parts', 'Option parts', 70, row);
    }

    const type = typeKey(row);
    const grade = effectiveMegaGrade(row);
    const subline = sublineKey(row);
    const line = lineKey(row);

    if (grade === 'eg') {
        return mkGrade('entry-grade', 'Entry Grade', 10, row);
    }

    if (grade === 'sd') {
        const sdLeaf = sdLeafFor(subline, type, line);

        return mkGradeWithLeaf('sd-gundam', 'SD Gundam', 20, sdLeaf, row);
    }

    if (grade === 'hg') {
        const hgLeaf = hgLeafFor(subline, type);

        return mkGradeWithLeaf('high-grade', 'High Grade', 30, hgLeaf, row);
    }

    if (grade === 'rg') {
        return mkGrade('real-grade', 'Real Grade', 40, row);
    }

    if (grade === 'mg' || grade === 'mgex' || grade === 'mgsd') {
        const mgLeaf = mgLeafFor(grade, subline);

        return mkGradeWithLeaf('master-grade', 'Master Grade', 50, mgLeaf, row);
    }

    if (grade === 'pg') {
        return mkGrade('perfect-grade', 'Perfect Grade', 60, row);
    }

    const series = seriesPlacement(row);
    if (series !== null) {
        return series;
    }

    if (grade === 'fm') {
        return mkGrade('full-mechanics', 'Full Mechanics', 210, row);
    }
    if (grade === 're') {
        return mkGrade('re-100', 'RE/100', 211, row);
    }
    if (grade === 'mega') {
        return mkGrade('mega-size', 'Mega Size', 212, row);
    }
    if (grade === 'ng') {
        return mkGrade('no-grade', 'No Grade', 213, row);
    }
    if (grade === 'fg') {
        return mkGrade('first-grade', 'First Grade', 214, row);
    }

    const productLine = fieldValue(row, 'product_line');
    if (productLine !== '') {
        return mkLine(`line:${normKey(productLine)}`, productLine, 220, row);
    }

    const leftoverType = fieldValue(row, 'type');
    if (leftoverType !== '') {
        return {
            section: 'model-kits',
            midKey: `type:${normKey(leftoverType)}`,
            midLabel: leftoverType,
            midOrder: 230,
            leafKey: null,
            leafLabel: null,
            leafOrder: 0,
            midKind: 'mk-type',
            leafKind: null,
        };
    }

    return {
        section: 'model-kits',
        midKey: 'other-kits',
        midLabel: 'Other kits',
        midOrder: 900,
        leafKey: null,
        leafLabel: null,
        leafOrder: 0,
        midKind: 'mk-line',
        leafKind: null,
    };
}

function seriesPlacement(row: InventoryByMainTypeReportRow): Placement | null {
    const line = lineKey(row);
    const type = typeKey(row);
    const grade = gradeKey(row);

    if (line.includes('30 minutes missions') || type === '30mm' || grade === '30mm') {
        return mkLine('30mm', '30 Minutes Missions', 90, row);
    }
    if (line.includes('30 minutes sisters') || type === '30ms' || grade === '30ms') {
        return mkLine('30ms', '30 Minutes Sisters', 91, row);
    }
    if (line.includes('30 minutes fantasy') || type === '30mf' || grade === '30mf') {
        return mkLine('30mf', '30 Minutes Fantasy', 92, row);
    }
    if (line.includes('30 minutes preference') || type === '30mp' || grade === '30mp') {
        return mkLine('30mp', '30 Minutes Preference', 93, row);
    }
    if (line.includes('pokemon') || type === 'pokemon') {
        return mkLine('pokemon', 'Pokémon', 100, row);
    }
    if (line === 'moderoid' || type === 'moderoid') {
        return mkLine('moderoid', 'MODEROID', 120, row);
    }
    if (line.includes('keroro') || type === 'keroro') {
        return type === 'keroro' && line === ''
            ? mkType('keroro', 'Keroro', 130, row)
            : mkLine('keroro', 'Keroro', 130, row);
    }
    if (line === 'snaa') {
        return mkLine('snaa', 'SNAA', 140, row);
    }
    if (line === 'mechatrowego' || type === 'mechatrowego') {
        return mkLine('mechatrowego', 'MechatroWeGo', 150, row);
    }
    if (line === 'plamax' || type === 'plamax' || grade === 'plamax') {
        return line === ''
            ? mkType('plamax', 'PLAMAX', 160, row)
            : mkLine('plamax', 'PLAMAX', 160, row);
    }
    if (line.includes('evangelion') || type.includes('evangelion')) {
        return mkLine('evangelion', 'Evangelion', 170, row);
    }
    if (line === 'kotobukiya' || (type === 'kotobukiya' && line === '')) {
        return line === ''
            ? mkType('kotobukiya', 'Kotobukiya', 110, row)
            : mkLine('kotobukiya', 'Kotobukiya', 110, row);
    }

    return null;
}

function placeToolsRow(row: InventoryByMainTypeReportRow): Placement {
    const shelf = shelfKey(row);
    const job = toolsJobForShelf(shelf);

    return {
        section: 'tools-supplies',
        midKey: job.key,
        midLabel: job.label,
        midOrder: job.order,
        leafKey: job.shelfKey,
        leafLabel: job.shelfLabel,
        leafOrder: job.shelfOrder,
        midKind: 'ts-job',
        leafKind: 'ts-shelf',
    };
}

function toolsJobForShelf(shelf: string): {
    key: string;
    label: string;
    order: number;
    shelfKey: string;
    shelfLabel: string;
    shelfOrder: number;
} {
    if (shelf === 'nippers & knives' || shelf === 'cutting' || shelf === 'nippers') {
        return jobShelf('building', 'Building', 10, 'nippers', 'Nippers & knives', 10);
    }
    if (shelf === 'sanding') {
        return jobShelf('building', 'Building', 10, 'sanding', 'Sanding', 20);
    }
    if (shelf === 'tweezers') {
        return jobShelf('building', 'Building', 10, 'tweezers', 'Tweezers', 30);
    }
    if (shelf === 'adhesives') {
        return jobShelf('building', 'Building', 10, 'adhesives', 'Adhesives', 40);
    }
    if (shelf === 'panel liners') {
        return jobShelf('detailing', 'Detailing', 20, 'panel-liners', 'Panel liners', 10);
    }
    if (shelf === 'markers') {
        return jobShelf('detailing', 'Detailing', 20, 'markers', 'Markers', 20);
    }
    if (shelf === 'decals') {
        return jobShelf('detailing', 'Detailing', 20, 'decals', 'Decals', 30);
    }
    if (shelf === 'weathering') {
        return jobShelf('detailing', 'Detailing', 20, 'weathering', 'Weathering', 40);
    }
    if (shelf === 'brushes') {
        return jobShelf('painting', 'Painting', 30, 'brushes', 'Brushes', 10);
    }
    if (shelf === 'airbrush' || shelf === 'airbrushes') {
        return jobShelf('painting', 'Painting', 30, 'airbrush', 'Airbrush', 20);
    }
    if (shelf === 'tapes') {
        return jobShelf('painting', 'Painting', 30, 'tapes', 'Tapes', 30);
    }
    if (shelf === 'paints') {
        return jobShelf('painting', 'Painting', 30, 'paints', 'Paints', 40);
    }
    if (shelf === 'scribing tools' || shelf === 'scribing') {
        return jobShelf('customization', 'Customization', 40, 'scribing', 'Scribing tools', 10);
    }
    if (shelf === 'drills & bits' || shelf === 'drills') {
        return jobShelf('customization', 'Customization', 40, 'drills', 'Drills', 20);
    }
    if (
        shelf === '' ||
        shelf === 'other' ||
        shelf === 'workshop-misc' ||
        shelf === 'workshop misc'
    ) {
        return jobShelf('building', 'Building', 10, 'other', 'Other', 90);
    }

    return jobShelf('building', 'Building', 10, `shelf:${shelf}`, fieldLabel(shelf), 90);
}

function jobShelf(
    key: string,
    label: string,
    order: number,
    shelfKey: string,
    shelfLabel: string,
    shelfOrder: number,
): {
    key: string;
    label: string;
    order: number;
    shelfKey: string;
    shelfLabel: string;
    shelfOrder: number;
} {
    return { key, label, order, shelfKey, shelfLabel, shelfOrder };
}

function sdLeafFor(
    subline: string,
    type: string,
    line: string,
): { key: string; label: string; order: number } {
    if (subline === 'ex-standard' || type === 'ex-standard') {
        return { key: 'ex-standard', label: 'EX-Standard', order: 10 };
    }
    if (subline === 'cross silhouette' || subline === 'cross_silhouette') {
        return { key: 'cross-silhouette', label: 'Cross Silhouette', order: 20 };
    }
    if (subline === 'sdw' || subline === 'sd world heroes' || type === 'sdw') {
        return { key: 'sdw', label: 'SD World Heroes', order: 30 };
    }
    if (subline === 'bb senshi' || subline === 'bb_senshi' || subline === 'bb') {
        return { key: 'bb-senshi', label: 'BB Senshi', order: 40 };
    }
    if (subline === 'g generation' || subline === 'g_generation') {
        return { key: 'g-generation', label: 'G Generation', order: 50 };
    }
    if (
        subline === 'sangoku soketsuden' ||
        subline === 'sangoku' ||
        subline === 'sangokusoketsuden'
    ) {
        return { key: 'sangoku', label: 'Sangoku Soketsuden', order: 65 };
    }
    if (subline === 'sdbf' || type === 'sdbf') {
        return { key: 'sdbf', label: 'Build Fighters', order: 60 };
    }
    if (
        subline === 'gunpla-kun' ||
        subline === 'gunpla_kun' ||
        line === 'gunpla-kun' ||
        type === 'kun dx'
    ) {
        return { key: 'gunpla-kun', label: 'Gunpla-kun', order: 70 };
    }

    return { key: 'other-sd', label: 'Other', order: 90 };
}

function hgLeafFor(subline: string, type: string): { key: string; label: string; order: number } {
    const token = subline !== '' ? subline : type;
    if (token === 'hguc') {
        return { key: 'hguc', label: 'Universal Century', order: 10 };
    }
    if (token === 'hgce') {
        return { key: 'hgce', label: 'Gundam SEED', order: 20 };
    }
    if (token === 'hgac') {
        return { key: 'hgac', label: 'After Colony', order: 30 };
    }
    if (token === 'hgibo') {
        return { key: 'hgibo', label: 'Iron-Blooded Orphans', order: 40 };
    }
    if (token === 'hgbf') {
        return { key: 'hgbf', label: 'Build Fighters', order: 50 };
    }
    if (token === 'hgbd') {
        return { key: 'hgbd', label: 'Build Divers', order: 60 };
    }

    return { key: 'other-hg', label: 'Other', order: 90 };
}

function mgLeafFor(grade: string, subline: string): { key: string; label: string; order: number } {
    if (grade === 'mgex' || subline === 'mgex') {
        return { key: 'mgex', label: 'MGEX', order: 30 };
    }
    if (grade === 'mgsd' || subline === 'mgsd') {
        return { key: 'mgsd', label: 'MGSD', order: 40 };
    }
    if (subline === 'ver.ka' || subline === 'ver ka' || subline === 'ver_ka') {
        return { key: 'ver-ka', label: 'MG Ver.Ka', order: 20 };
    }

    return { key: 'mg', label: 'MG', order: 10 };
}

function mkGrade(
    key: string,
    label: string,
    order: number,
    _row: InventoryByMainTypeReportRow,
): Placement {
    return {
        section: 'model-kits',
        midKey: key,
        midLabel: label,
        midOrder: order,
        leafKey: null,
        leafLabel: null,
        leafOrder: 0,
        midKind: 'mk-grade',
        leafKind: null,
    };
}

function mkGradeWithLeaf(
    key: string,
    label: string,
    order: number,
    leaf: { key: string; label: string; order: number },
    _row: InventoryByMainTypeReportRow,
): Placement {
    return {
        section: 'model-kits',
        midKey: key,
        midLabel: label,
        midOrder: order,
        leafKey: leaf.key,
        leafLabel: leaf.label,
        leafOrder: leaf.order,
        midKind: 'mk-grade',
        leafKind: 'mk-subline',
    };
}

function mkLine(
    key: string,
    label: string,
    order: number,
    _row: InventoryByMainTypeReportRow,
): Placement {
    return {
        section: 'model-kits',
        midKey: key,
        midLabel: label,
        midOrder: order,
        leafKey: null,
        leafLabel: null,
        leafOrder: 0,
        midKind: 'mk-line',
        leafKind: null,
    };
}

function mkType(
    key: string,
    label: string,
    order: number,
    _row: InventoryByMainTypeReportRow,
): Placement {
    return {
        section: 'model-kits',
        midKey: key,
        midLabel: label,
        midOrder: order,
        leafKey: null,
        leafLabel: null,
        leafOrder: 0,
        midKind: 'mk-type',
        leafKind: null,
    };
}

function miscPlacement(
    key: string,
    label: string,
    order: number,
    _row: InventoryByMainTypeReportRow,
): Placement {
    return {
        section: 'miscellaneous',
        midKey: key,
        midLabel: label,
        midOrder: order,
        leafKey: null,
        leafLabel: null,
        leafOrder: 0,
        midKind: 'misc-line',
        leafKind: null,
    };
}

function isModelKitShelf(row: InventoryByMainTypeReportRow): boolean {
    const department = departmentKey(row);
    if (department === 'model kits') {
        return true;
    }

    return lineKey(row) === 'gunpla' && (department === 'misc' || department === 'accessories');
}

function isThirtyMinutes(row: InventoryByMainTypeReportRow): boolean {
    const line = lineKey(row);
    const type = typeKey(row);
    const grade = gradeKey(row);

    return (
        line.includes('30 minutes') ||
        type === '30mm' ||
        type === '30ms' ||
        type === '30mf' ||
        type === '30mp' ||
        grade === '30mm' ||
        grade === '30ms' ||
        grade === '30mf' ||
        grade === '30mp'
    );
}

function isActionBase(row: InventoryByMainTypeReportRow): boolean {
    return lineKey(row) === 'action base' || typeKey(row) === 'action base';
}

function isOptionParts(row: InventoryByMainTypeReportRow): boolean {
    const line = lineKey(row);
    const type = typeKey(row);

    return (
        line === 'builders parts hd' ||
        line === 'option system' ||
        type === 'option parts' ||
        type === 'option parts set'
    );
}

function isKeychain(row: InventoryByMainTypeReportRow): boolean {
    return lineKey(row) === 'keychains' || lineKey(row) === 'keychain';
}

function isCcsToys(row: InventoryByMainTypeReportRow): boolean {
    return (
        lineKey(row) === 'ccs toys' || typeKey(row) === 'ccs toys' || gradeKey(row) === 'ccs_toys'
    );
}

function drillDownFromRows(
    rows: InventoryByMainTypeReportRow[],
    include: DrillDownFields,
): InventoryTaxonomyDrillDown {
    return {
        departments: include.department ? uniqueKeys(rows.map((row) => departmentKey(row))) : [],
        productLines: include.product_line
            ? uniqueKeys(rows.map((row) => row.product_line ?? ''))
            : [],
        workshopShelves: include.workshop_shelf
            ? uniqueKeys(rows.map((row) => row.workshop_shelf ?? ''))
            : [],
        grades: include.grade ? uniqueKeys(rows.map((row) => row.grade ?? '')) : [],
        sublines: include.subline ? uniqueKeys(rows.map((row) => row.subline ?? '')) : [],
        types: include.type ? uniqueKeys(rows.map((row) => row.type)) : [],
    };
}

function departmentKey(row: InventoryByMainTypeReportRow): string {
    return normKey(row.department || row.main_type);
}

function gradeKey(row: InventoryByMainTypeReportRow): string {
    return normKey(row.grade ?? '');
}

/** Prefer products.type when it is a real Gunpla grade so a mistagged grade column cannot move an HG into RG. */
function effectiveMegaGrade(row: InventoryByMainTypeReportRow): string {
    const fromType = gradeFromTypeToken(typeKey(row));
    if (fromType !== null) {
        return fromType;
    }

    return gradeKey(row);
}

function gradeFromTypeToken(type: string): string | null {
    return (
        {
            eg: 'eg',
            'entry grade': 'eg',
            hg: 'hg',
            hguc: 'hg',
            hgbf: 'hg',
            hgce: 'hg',
            hgac: 'hg',
            hgfc: 'hg',
            hgbc: 'hg',
            hgaw: 'hg',
            hgbd: 'hg',
            hgibo: 'hg',
            'orphans hg': 'hg',
            rg: 'rg',
            mg: 'mg',
            mgex: 'mgex',
            mgsd: 'mgsd',
            pg: 'pg',
            sd: 'sd',
            bb: 'sd',
            sdw: 'sd',
            sdbf: 'sd',
            'ex-standard': 'sd',
            fm: 'fm',
            re: 're',
            mega: 'mega',
            ng: 'ng',
        }[type] ?? null
    );
}

function typeKey(row: InventoryByMainTypeReportRow): string {
    return normKey(row.type);
}

function lineKey(row: InventoryByMainTypeReportRow): string {
    return normKey(row.product_line ?? '');
}

function sublineKey(row: InventoryByMainTypeReportRow): string {
    return normKey(row.subline ?? '');
}

function shelfKey(row: InventoryByMainTypeReportRow): string {
    return normKey(row.workshop_shelf ?? '');
}

function fieldValue(
    row: InventoryByMainTypeReportRow,
    field: 'product_line' | 'department',
): string {
    if (field === 'department') {
        return (row.department || row.main_type).trim();
    }

    return (row[field] ?? '').trim();
}

function fieldLabel(value: string): string {
    return value === '' ? UNSET_LABEL : value;
}

function uniqueKeys(values: string[]): string[] {
    return [...new Set(values.map((value) => value.trim()))];
}

function normKey(value: string): string {
    return value.trim().toLocaleLowerCase();
}

function sortSectionNodes(
    nodes: InventoryTaxonomyTreeNode[],
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryTaxonomyTreeNode[] {
    if (sortBy === 'type_label') {
        return [...nodes].sort((left, right) => {
            const result =
                (MEGA_SECTION_ORDER[left.key] ?? 50) - (MEGA_SECTION_ORDER[right.key] ?? 50);

            return sortDir === 'asc' ? result : -result;
        });
    }

    return sortByTotals(nodes, sortBy, sortDir);
}

function sortMidNodes(
    nodes: InventoryTaxonomyTreeNode[],
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryTaxonomyTreeNode[] {
    if (sortBy === 'type_label') {
        return nodes;
    }

    return sortByTotals(nodes, sortBy, sortDir);
}

function sortLeafNodes(
    nodes: InventoryTaxonomyTreeNode[],
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryTaxonomyTreeNode[] {
    if (sortBy === 'type_label') {
        return nodes;
    }

    return sortByTotals(nodes, sortBy, sortDir);
}

function sortByTotals(
    nodes: InventoryTaxonomyTreeNode[],
    sortBy: InventoryByMainTypeSortKey,
    sortDir: 'asc' | 'desc',
): InventoryTaxonomyTreeNode[] {
    const sorted = [...nodes];
    sorted.sort((left, right) =>
        compareInventoryByMainTypeRows(
            {
                type: left.key,
                type_label: left.label,
                main_type: left.key,
                ...left.totals,
            },
            {
                type: right.key,
                type_label: right.label,
                main_type: right.key,
                ...right.totals,
            },
            sortBy,
            sortDir,
        ),
    );

    return sorted;
}

export function flattenVisibleInventoryTree(
    nodes: InventoryTaxonomyTreeNode[],
    expanded: Set<string>,
): InventoryTaxonomyTreeNode[] {
    const visible: InventoryTaxonomyTreeNode[] = [];
    for (const node of nodes) {
        visible.push(node);
        if (node.children.length > 0 && expanded.has(node.key)) {
            visible.push(...flattenVisibleInventoryTree(node.children, expanded));
        }
    }

    return visible;
}
