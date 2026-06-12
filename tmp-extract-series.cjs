const fs = require('fs');
const path = require('path');

const htmlPath = path.join(
  __dirname,
  'storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html',
);
const h = fs.readFileSync(htmlPath, 'utf8');
const matches = [...h.matchAll(/\{"id":(\d+),"name":"([^"]+)"\}/g)].map((m) => ({
  id: m[1],
  name: m[2],
}));
const uniq = new Map();
for (const x of matches) {
  if (!uniq.has(x.name)) uniq.set(x.name, x);
}
const all = [...uniq.values()].sort((a, b) => a.name.localeCompare(b.name));
console.log('series_count=' + all.length);
for (const x of all) {
  console.log(`${x.id} | ${x.name}`);
}
