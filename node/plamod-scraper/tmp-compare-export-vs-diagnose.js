const {
  exportManufacturerInstockMerged,
  diagnoseManufacturerFilterScroll,
  resetPlamodScraperSessions,
} = require('./src/plamod');

async function main() {
  await resetPlamodScraperSessions();

  console.log('=== diagnose scroll ===');
  const diag = await diagnoseManufacturerFilterScroll({
    categoryId: '1004',
    expectedCount: 191,
  });
  console.log('diagnose', diag);

  await resetPlamodScraperSessions();

  console.log('=== merged export max_filters=1 ===');
  const merged = await exportManufacturerInstockMerged({ manufacturerId: 1, maxFilters: 1 });
  console.log(
    'merged',
    JSON.stringify({
      ok: merged.ok,
      row_count: merged.row_count,
      chunks: merged.filter_chunks,
      error: merged.error_message,
    }),
  );
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
