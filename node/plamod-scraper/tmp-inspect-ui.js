const fs = require('fs');
const h = fs.readFileSync(process.argv[2], 'utf8');
for (const t of ['Show Filters', 'Filters', 'filter', 'lg:block', 'hidden lg:', 'drawer', 'Sheet']) {
  console.log(t, h.includes(t));
}
console.log('category labels', (h.match(/label for="category-/g) || []).length);
