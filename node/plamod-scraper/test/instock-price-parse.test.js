'use strict';

const assert = require('node:assert/strict');
const {
  parseInStockPriceFromCardText,
  isPreorderOnlyPriceBlock,
} = require('../src/plamod');

const offerClosedCard = [
  '5069164',
  '30MF CUSTOMIZE STRUCTURE1',
  'IN-STOCK',
  'CARTON 36 PACK 1 MOQ 1',
  'OFFER CLOSED Preorder Ended Fully Sold Out',
  'ETA: DEC 31',
  'PRICE: $10.20',
].join(' ');

assert.equal(parseInStockPriceFromCardText(offerClosedCard), '10.20');

const pdpText = 'Pricing & Inventory Stock Price: $10.20 Available Stock In Stock';
assert.equal(parseInStockPriceFromCardText(pdpText), '10.20');

const preorderOnly = 'PREORDER OFFER PO PRICE $8.50 ORDERED: 0';
assert.equal(parseInStockPriceFromCardText(preorderOnly), '');
assert.equal(isPreorderOnlyPriceBlock(preorderOnly), true);
assert.equal(isPreorderOnlyPriceBlock(offerClosedCard), false);

console.log('instock-price-parse.test.js ok');
