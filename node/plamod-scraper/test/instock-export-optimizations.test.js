'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const {
  manufacturerInstockFilterCachePath,
  readManufacturerInstockFilterCache,
  writeManufacturerInstockFilterCache,
  instockSliceShouldRetryListingPrices,
} = require('../src/plamod');

const tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'plamod-cache-test-'));

try {
  assert.equal(instockSliceShouldRetryListingPrices(0, 10), false);
  assert.equal(instockSliceShouldRetryListingPrices(3, 10), false);
  assert.equal(instockSliceShouldRetryListingPrices(5, 10), true);
  assert.equal(instockSliceShouldRetryListingPrices(8, 8), true);

  const cachePath = manufacturerInstockFilterCachePath(tmpRoot, '1');
  assert.match(cachePath, /instock_filter_cache[\\/]mfr-1\.json$/);

  const filters = [{ tab: 'BRAND', name: 'Bandai', category_id: '123', instock_count: 42 }];
  writeManufacturerInstockFilterCache(tmpRoot, '1', 709, filters);

  const hit = readManufacturerInstockFilterCache(tmpRoot, '1', 709, 60_000);
  assert.deepEqual(hit, filters);

  assert.equal(readManufacturerInstockFilterCache(tmpRoot, '1', 710, 60_000), null);

  const stalePath = manufacturerInstockFilterCachePath(tmpRoot, '2');
  fs.mkdirSync(path.dirname(stalePath), { recursive: true });
  fs.writeFileSync(
    stalePath,
    JSON.stringify({
      manufacturer_id: '2',
      expected_total: 100,
      cached_at: new Date(Date.now() - 120_000).toISOString(),
      filters,
    }),
    'utf8',
  );
  assert.equal(readManufacturerInstockFilterCache(tmpRoot, '2', 100, 60_000), null);

  console.log('instock-export-optimizations.test.js ok');
} finally {
  fs.rmSync(tmpRoot, { recursive: true, force: true });
}
