const fs = require('fs');
const h = fs.readFileSync(process.argv[2], 'utf8');
const idx = h.indexOf('title="Plastic Model Kits"');
console.log('idx', idx);
if (idx >= 0) {
  console.log(h.slice(idx - 400, idx + 500));
}

const checked = [...h.matchAll(/id="(category|brand|series)-(\d+)"[^>]*data-state="checked"/g)];
console.log('checked count', checked.length);
for (const m of checked.slice(0, 5)) {
  console.log('checked', m[1], m[2]);
}

const urlMatch = h.match(/manufacturerCategoryId=\d+/g);
console.log('url params', urlMatch ? [...new Set(urlMatch)].slice(0, 10) : []);
