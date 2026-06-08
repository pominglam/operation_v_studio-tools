const fs = require('node:fs');

const html = fs.readFileSync(process.argv[2], 'utf8');
const patterns = ['Page', 'page', 'of', 'Next', 'Previous', '1 /', 'Showing', 'per page', 'rows per'];
for (const p of patterns) {
  const idx = html.indexOf(p);
  if (idx >= 0) {
    console.log('\n==', p, '==');
    console.log(html.slice(Math.max(0, idx - 100), idx + 200).replace(/\s+/g, ' '));
  }
}
