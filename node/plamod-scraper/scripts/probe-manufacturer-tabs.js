const fs = require('node:fs');

const htmlPath = process.argv[2];
const html = fs.readFileSync(htmlPath, 'utf8');

function contexts(label) {
  const out = [];
  let idx = 0;
  while (true) {
    idx = html.indexOf(label, idx);
    if (idx < 0) break;
    out.push(html.slice(Math.max(0, idx - 250), idx + 350).replace(/\s+/g, ' '));
    idx += label.length;
    if (out.length >= 8) break;
  }
  return out;
}

for (const label of ['>Preorder<', '>In-Stock<', '>Plastic Model Kits<', '>BANDAI HOBBY<']) {
  console.log('\n===', label, '===');
  contexts(label).forEach((c, i) => console.log(i + 1, c.slice(0, 500)));
}
