const fs = require('node:fs');

const html = fs.readFileSync(process.argv[2], 'utf8');
for (const label of ['>In-Stock<', '>Preorder<', '>Out of Stock<', 'manufacturers=1', 'categories=1', 'tab=preorder']) {
  console.log(label, html.includes(label.replace(/^>|<$/, '')) || html.includes(label));
}
