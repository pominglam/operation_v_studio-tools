'use strict';

const assert = require('node:assert/strict');
const { retailerPdpSkuFromUrl, isOnRetailerPdpForSku } = require('../src/plamod');

assert.equal(retailerPdpSkuFromUrl('https://plamod.com/retailer/products/0154256'), '0154256');
assert.equal(retailerPdpSkuFromUrl('https://plamod.com/retailer/products/0154256?tab=preorder'), '0154256');
assert.equal(
  isOnRetailerPdpForSku('https://plamod.com/retailer/products/0154256', '0154256'),
  true,
);
assert.equal(
  isOnRetailerPdpForSku('https://plamod.com/retailer/products/0157348', '0154256'),
  false,
);

console.log('pdp-url.test.js ok');
