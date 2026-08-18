const fs = require('fs');
const file = process.argv[2];
const text = fs.readFileSync(file, 'utf8');
const keys = new Set();
for (const m of text.matchAll(/"([a-zA-Z_]+)"\s*:\s*\[\{"id"/g)) keys.add(m[1]);
console.log('array keys with id objects', [...keys]);
for (const key of ['series', 'categories', 'brands', 'brand', 'lines']) {
  const anchor = `"${key}":[{"id"`;
  const idx = text.indexOf(anchor);
  if (idx >= 0) {
    console.log('\n', key, 'sample:', text.slice(idx, idx + 500));
  }
}
