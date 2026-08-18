const fs = require('fs');
const h = fs.readFileSync(process.argv[2], 'utf8');
const ids = [...h.matchAll(/"(?:id|brandId|categoryId|seriesId)"\s*:\s*(\d+)[\s\S]{0,80}?"name"\s*:\s*"((?:\\.|[^"\\])*)"/g)];
const names = new Map();
for (const m of ids) {
  names.set(m[2].replace(/\\"/g, '"'), m[1]);
}
console.log('embedded name/id pairs', names.size);
for (const [name, id] of [...names.entries()].filter(([n]) => /HGUC|Entry Grade|30 Minutes|Plastic Model/i.test(n)).slice(0, 15)) {
  console.log(name, id);
}
const labels = [...h.matchAll(/label[^>]+for="(category|brand|series)-(\d+)"[^>]+title="([^"]+)"/g)];
console.log('label ids', labels.length);
for (const m of labels.filter((x) => /HGUC|Entry Grade|30 Minutes|Plastic Model/i.test(x[3])).slice(0, 10)) {
  console.log(m[1], m[2], m[3]);
}
