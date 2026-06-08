const fs = require('node:fs');

const html = fs.readFileSync(process.argv[2], 'utf8');
console.log('PREORDER count', (html.match(/PREORDER/g) || []).length);
console.log('has manufacturers=1', html.includes('manufacturers=1'));
console.log('has categories=1', /categories=1(?:&|$)/.test(html));
const urlMatch = html.match(/retailer\/search\?[^"'\\s]+/);
console.log('search url snippet', urlMatch ? urlMatch[0].slice(0, 120) : null);
