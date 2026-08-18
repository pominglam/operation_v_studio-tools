const fs = require('fs');

const htmlPath = process.argv[2];
const h = fs.readFileSync(htmlPath, 'utf8');

for (const key of ['categories', 'brands', 'series']) {
  const anchor = h.indexOf(`"${key}":[{"id"`);
  console.log(`${key} anchor`, anchor);
  if (anchor >= 0) {
    const slice = h.slice(anchor, anchor + 2000);
    const hits = [...slice.matchAll(/\{"id":(\d+),"name":"((?:\\.|[^"\\])*)"/g)].slice(0, 8);
    for (const m of hits) {
      const name = m[2].replace(/\\"/g, '"');
      console.log(`  ${m[1]} ${name}`);
    }
  }
}

const pmkIdx = h.indexOf('Plastic Model');
console.log('Plastic Model idx', pmkIdx);
if (pmkIdx >= 0) {
  console.log(h.slice(pmkIdx - 80, pmkIdx + 120));
}
