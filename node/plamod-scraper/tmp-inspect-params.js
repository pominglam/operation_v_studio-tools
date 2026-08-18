const fs = require('fs');
const h = fs.readFileSync(process.argv[2], 'utf8');
const params = new Set();
for (const m of h.matchAll(/manufacturer[A-Za-z]*Id/g)) params.add(m[0]);
console.log('params', [...params]);
for (const m of h.matchAll(/for="(category|brand|series)-(\d+)"/g)) {
  console.log('for', m[1], m[2]);
  if (['1003', '1043'].includes(m[2])) {
    const idx = h.indexOf(m[0]);
    console.log(h.slice(idx - 80, idx + 120));
  }
}
