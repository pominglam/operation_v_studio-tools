const fs = require('node:fs');

const html = fs.readFileSync(process.argv[2], 'utf8');
const idx = html.indexOf('lucide-chevron-right h-4 w-4');
console.log('chevron-right count', (html.match(/lucide-chevron-right/g) || []).length);
const snippets = [];
let pos = 0;
while (snippets.length < 8) {
  const i = html.indexOf('lucide-chevron-right', pos);
  if (i < 0) break;
  snippets.push(html.slice(Math.max(0, i - 180), i + 220).replace(/\s+/g, ' '));
  pos = i + 20;
}
snippets.forEach((s, n) => console.log(`\n${n + 1}`, s.slice(0, 350)));
