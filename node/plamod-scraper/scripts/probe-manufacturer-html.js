const fs = require('node:fs');

const htmlPath = process.argv[2];
if (!htmlPath) {
  console.error('usage: node probe-manufacturer-html.js <html-path>');
  process.exit(1);
}

const html = fs.readFileSync(htmlPath, 'utf8');
const links = [...html.matchAll(/href="([^"]*\/retailer\/products\/[^"]+)"/g)].map((m) => m[1]);
console.log('product_links', links.length);
console.log('sample', links.slice(0, 8).join('\n'));

for (const label of ['Preorder', 'Preorders', 'In-Stock', 'Out of Stock', 'Plastic Model Kits', 'STOCK', 'CSV']) {
  const re = new RegExp(`>${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}[^<]{0,30}<`, 'gi');
  const hits = [...html.matchAll(re)].map((m) => m[0].replace(/\s+/g, ' '));
  console.log(`label:${label}`, hits.slice(0, 5));
}
