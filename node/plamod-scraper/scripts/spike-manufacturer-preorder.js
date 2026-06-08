const path = require('node:path');
const fs = require('node:fs');
const { exportManufacturerPreordersCsv } = require('../src/plamod');

async function runOnce(label, opts) {
  console.log(`\n=== ${label} ===`);
  const out = await exportManufacturerPreordersCsv(opts);
  console.log(JSON.stringify(out, null, 2));
  return out;
}

async function main() {
  // First attempt with fixed tab logic will be in plamod.js; try variants.
  const attempts = [
    ['default', { manufacturerId: 1, tab: 'Preorder', category: 'Plastic Model Kits' }],
    ['no-category', { manufacturerId: 1, tab: 'Preorder', category: null }],
  ];

  for (const [label, opts] of attempts) {
    const out = await runOnce(label, opts);
    if (out.ok && out.row_count >= 620 && (out.has_vigna_sku || out.has_vigna_name)) {
      console.log('SUCCESS', label);
      return;
    }
  }
  process.exitCode = 2;
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
