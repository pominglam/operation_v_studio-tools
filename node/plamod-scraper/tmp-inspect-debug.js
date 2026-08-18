const fs = require('fs');
const file = process.argv[2];
const h = fs.readFileSync(file, 'utf8');
console.log('PMK', h.includes('Plastic Model Kits'));
console.log('HGUC label', h.includes('title="HGUC"'));
console.log('products', (h.match(/retailer\/products/g) || []).length);
const idx = h.indexOf('title="HGUC"');
if (idx >= 0) console.log(h.slice(idx - 200, idx + 200));
