import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';

function usageAndExit() {
  // Keep output minimal (non-interactive).
  console.error(
    [
      'Usage:',
      '  node scripts/shopify-archive-missing-handles.mjs "<path-to-shopify-products-export.csv>" [--full-header]',
      '',
      'Output:',
      '  storage/app/private/exports/shopify-archive-missing-handles-*.csv',
      '  storage/app/private/exports/shopify-archive-missing-handles-*.txt',
    ].join('\n'),
  );
  process.exit(2);
}

const args = process.argv.slice(2);
if (args.length < 1) usageAndExit();

const inputPath = args[0];
const fullHeader = args.includes('--full-header');

if (!fs.existsSync(inputPath)) {
  console.error(`Input file not found: ${inputPath}`);
  process.exit(1);
}

// Must match `App\Services\Products\ProductExportService::shopifyHeader()`.
const APP_SHOPIFY_HEADER = [
  'Handle',
  'Title',
  'Body (HTML)',
  'Vendor',
  'Product Category',
  'Type',
  'Tags',
  'Published',
  'Published Scope',
  'Option1 Name',
  'Option1 Value',
  'Option1 Linked To',
  'Option2 Name',
  'Option2 Value',
  'Option2 Linked To',
  'Option3 Name',
  'Option3 Value',
  'Option3 Linked To',
  'Variant SKU',
  'Variant Grams',
  'Variant Inventory Tracker',
  'Variant Inventory Qty',
  'Variant Inventory Policy',
  'Variant Fulfillment Service',
  'Price',
  'Variant Compare At Price',
  'Variant Requires Shipping',
  'Variant Taxable',
  'Unit Price Total Measure',
  'Unit Price Total Measure Unit',
  'Unit Price Base Measure',
  'Unit Price Base Measure Unit',
  'Variant Barcode',
  'Image Src',
  'Image Position',
  'Image Alt Text',
  'Gift Card',
  'SEO Title',
  'SEO Description',
  'Variant Image',
  'Variant Weight Unit',
  'Variant Tax Code',
  'Cost per item',
  'Status',
];

function csvEscape(value) {
  const v = value === null || value === undefined ? '' : String(value);
  if (v.includes('"') || v.includes(',') || v.includes('\n') || v.includes('\r')) {
    return `"${v.replaceAll('"', '""')}"`;
  }
  return v;
}

/**
 * Parses CSV text into rows, handling quoted fields/newlines.
 * Returns:
 * - headers: string[]
 * - handleSet: Set<string>
 * - skuByHandle: Map<string, string>
 * - bestRowByHandle: Map<string, Record<string, string>>
 */
function parseShopifyExportForHandles(text) {
  /** @type {string[] | null} */
  let headers = null;

  let handleIdx = 0;
  let skuIdx = -1;
  let publishedIdx = -1;
  let statusIdx = -1;

  const handleSet = new Set();
  const skuByHandle = new Map();
  const bestRowByHandle = new Map();

  let inQuotes = false;
  let field = '';
  /** @type {string[]} */
  let row = [];

  const pushField = () => {
    row.push(field);
    field = '';
  };

  const finishRow = () => {
    pushField();
    if (headers === null) {
      headers = row.map((h) => String(h ?? '').trim());
      handleIdx = headers.findIndex((h) => h === 'Handle');
      if (handleIdx < 0) handleIdx = 0;
      skuIdx = headers.findIndex((h) => h === 'Variant SKU');
      publishedIdx = headers.findIndex((h) => h === 'Published');
      statusIdx = headers.findIndex((h) => h === 'Status');
      row = [];
      return;
    }

    const handle = String(row[handleIdx] ?? '').trim();
    if (handle !== '') {
      handleSet.add(handle);
      if (!skuByHandle.has(handle) && skuIdx >= 0) {
        const sku = String(row[skuIdx] ?? '').trim();
        if (sku !== '') skuByHandle.set(handle, sku);
      }

      // Keep the "best" row for each handle: prefer one that has a Title and Variant SKU.
      if (!bestRowByHandle.has(handle)) {
        /** @type {Record<string, string>} */
        const obj = {};
        for (let i = 0; i < headers.length; i++) {
          obj[headers[i]] = String(row[i] ?? '');
        }
        bestRowByHandle.set(handle, obj);
      } else {
        const existing = bestRowByHandle.get(handle);
        const currentTitle = String(row[headers.indexOf('Title')] ?? '').trim();
        const currentSku =
          skuIdx >= 0 ? String(row[skuIdx] ?? '').trim() : '';

        const existingTitle = String(existing?.Title ?? '').trim();
        const existingSku = String(existing?.['Variant SKU'] ?? '').trim();

        const currentScore = (currentTitle !== '' ? 2 : 0) + (currentSku !== '' ? 1 : 0);
        const existingScore = (existingTitle !== '' ? 2 : 0) + (existingSku !== '' ? 1 : 0);

        if (currentScore > existingScore) {
          /** @type {Record<string, string>} */
          const obj = {};
          for (let i = 0; i < headers.length; i++) {
            obj[headers[i]] = String(row[i] ?? '');
          }
          bestRowByHandle.set(handle, obj);
        }
      }
    }

    row = [];
  };

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];

    if (inQuotes) {
      if (ch === '"') {
        const next = text[i + 1] ?? '';
        if (next === '"') {
          field += '"';
          i += 1;
          continue;
        }
        inQuotes = false;
        continue;
      }
      field += ch;
      continue;
    }

    if (ch === '"') {
      inQuotes = true;
      continue;
    }

    if (ch === ',') {
      pushField();
      continue;
    }

    if (ch === '\n') {
      finishRow();
      continue;
    }

    if (ch === '\r') {
      continue;
    }

    field += ch;
  }

  if (field !== '' || row.length > 0) {
    finishRow();
  }

  if (headers === null) {
    throw new Error('No CSV headers found.');
  }

  return {
    headers,
    indices: { handleIdx, skuIdx, publishedIdx, statusIdx },
    handleSet,
    skuByHandle,
    bestRowByHandle,
  };
}

function loadSystemHandlesFromDb() {
  const cmd =
    'docker compose exec -T mysql mysql -upricing_tool -ppricing_tool -D pricing_tool -N -e "select handle from products where handle is not null and handle <> \'\'"';
  const out = execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  const set = new Set();
  for (const line of out.split(/\r?\n/g)) {
    const h = line.trim();
    if (h !== '') set.add(h);
  }
  return set;
}

const raw = fs.readFileSync(inputPath, 'utf8');
const parsed = parseShopifyExportForHandles(raw);

const systemHandles = loadSystemHandlesFromDb();

const missing = Array.from(parsed.handleSet).filter((h) => !systemHandles.has(h));
missing.sort((a, b) => a.localeCompare(b));

const exportsDir = path.resolve('storage/app/private/exports');
fs.mkdirSync(exportsDir, { recursive: true });

const ts = new Date()
  .toISOString()
  .replaceAll(':', '')
  .replaceAll('-', '')
  .replaceAll('.', '')
  .replace('T', '-')
  .replace('Z', '');

const csvOutPath = path.join(
  exportsDir,
  `shopify-archive-missing-handles-${ts}${fullHeader ? '-app-full' : ''}.csv`,
);
const reportOutPath = path.join(exportsDir, `shopify-archive-missing-handles-${ts}.txt`);

let headerOut;
let rowsOut = [];

if (fullHeader) {
  headerOut = APP_SHOPIFY_HEADER;
  const handleIdx = headerOut.findIndex((h) => h === 'Handle');
  const publishedIdx = headerOut.findIndex((h) => h === 'Published');
  const publishedScopeIdx = headerOut.findIndex((h) => h === 'Published Scope');
  const statusIdx = headerOut.findIndex((h) => h === 'Status');

  for (const h of missing) {
    const row = Array.from({ length: headerOut.length }, () => '');
    row[handleIdx] = h;
    if (publishedIdx >= 0) row[publishedIdx] = 'FALSE';
    if (publishedScopeIdx >= 0) row[publishedScopeIdx] = 'global';
    if (statusIdx >= 0) row[statusIdx] = 'archived';

    // Populate required fields (Title, options, SKU, etc.) using Shopify's exported row for that handle.
    const src = parsed.bestRowByHandle.get(h);
    if (src) {
      for (let i = 0; i < headerOut.length; i++) {
        const col = headerOut[i];
        if (col === 'Handle' || col === 'Published' || col === 'Published Scope' || col === 'Status') {
          continue;
        }

        if (col === 'Price') {
          const v = String(src['Variant Price'] ?? src.Price ?? '').trim();
          if (v !== '') row[i] = v;
          continue;
        }

        const v = String(src[col] ?? '').trim();
        if (v !== '') row[i] = v;
      }
    }

    // Final safety net for Shopify import validation.
    const titleIdx = headerOut.findIndex((c) => c === 'Title');
    const opt1NameIdx = headerOut.findIndex((c) => c === 'Option1 Name');
    const opt1ValIdx = headerOut.findIndex((c) => c === 'Option1 Value');
    if (titleIdx >= 0 && String(row[titleIdx] ?? '').trim() === '') row[titleIdx] = h;
    if (opt1NameIdx >= 0 && String(row[opt1NameIdx] ?? '').trim() === '') row[opt1NameIdx] = 'Title';
    if (opt1ValIdx >= 0 && String(row[opt1ValIdx] ?? '').trim() === '') row[opt1ValIdx] = 'Default Title';

    rowsOut.push(row);
  }
} else {
  headerOut = ['Handle', 'Status'];
  for (const h of missing) {
    rowsOut.push([h, 'archived']);
  }
}

const csvLines = [];
csvLines.push(headerOut.map(csvEscape).join(','));
for (const row of rowsOut) {
  csvLines.push(row.map(csvEscape).join(','));
}
fs.writeFileSync(csvOutPath, csvLines.join('\r\n') + '\r\n', 'utf8');

const reportLines = [];
reportLines.push(`input=${inputPath}`);
reportLines.push(`shopify_unique_handles=${parsed.handleSet.size}`);
reportLines.push(`system_handles=${systemHandles.size}`);
reportLines.push(`missing_handles=${missing.length}`);
reportLines.push('');
reportLines.push('missing handles (handle, variant_sku_if_known):');
for (const h of missing) {
  const sku = parsed.skuByHandle.get(h) ?? '';
  reportLines.push(`${h}${sku ? `,${sku}` : ''}`);
}
fs.writeFileSync(reportOutPath, reportLines.join('\r\n') + '\r\n', 'utf8');

console.log(JSON.stringify({ csv: csvOutPath, report: reportOutPath, missing: missing.length }));

