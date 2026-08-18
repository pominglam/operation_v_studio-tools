const fs = require('fs');
const t = fs.readFileSync(process.argv[2], 'utf8');
const hits = [...t.matchAll(/manufacturerCategoryId":(\d+)/g)].map((m) => m[1]);
const freq = new Map();
for (const id of hits) freq.set(id, (freq.get(id) || 0) + 1);
console.log('top manufacturerCategoryId', [...freq.entries()].sort((a, b) => b[1] - a[1]).slice(0, 15));
console.log('has id 1', t.includes('manufacturerCategoryId":1,'));
