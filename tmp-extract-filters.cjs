const fs = require('fs');
const path = require('path');

const htmlPath = path.join(
  __dirname,
  'storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html',
);
const h = fs.readFileSync(htmlPath, 'utf8');

const anchor = h.indexOf('"categories":[{"id"');
if (anchor < 0) {
  console.log('categories anchor not found');
  process.exit(1);
}

const slice = h.slice(anchor, anchor + 12000);
const categories = [...slice.matchAll(/\{"id":(\d+),"name":"((?:\\.|[^"\\])*)"/g)].map((m) => ({
  id: m[1],
  name: m[2].replace(/\\"/g, '"'),
}));

const seriesAnchor = h.indexOf('"series":[{"id"');
let series = [];
if (seriesAnchor >= 0) {
  const sslice = h.slice(seriesAnchor, seriesAnchor + 20000);
  series = [...sslice.matchAll(/\{"id":(\d+),"name":"((?:\\.|[^"\\])*)"/g)].map((m) => ({
    id: m[1],
    name: m[2].replace(/\\"/g, '"'),
  }));
}

console.log('=== CATEGORY / product-line filters (Bandai manufacturer page) ===');
console.log('count=' + categories.length);
for (const x of categories.sort((a, b) => a.name.localeCompare(b.name))) {
  console.log(`${x.id} | ${x.name}`);
}

console.log('\n=== SERIES tab filters (Bandai manufacturer page) ===');
console.log('count=' + series.length);
for (const x of series.sort((a, b) => a.name.localeCompare(b.name))) {
  console.log(`${x.id} | ${x.name}`);
}
