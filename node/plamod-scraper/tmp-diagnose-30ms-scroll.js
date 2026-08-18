const { diagnoseManufacturerFilterScroll } = require('./src/plamod');

diagnoseManufacturerFilterScroll({
  manufacturerId: 1,
  categoryId: '1004',
  expectedCount: 191,
  maxStaleRounds: 60,
})
  .then((result) => {
    console.log('RESULT', JSON.stringify(result));
  })
  .catch((error) => {
    console.error(error);
    process.exit(1);
  });
