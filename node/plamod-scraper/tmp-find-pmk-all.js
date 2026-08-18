const fs = require('fs');
const h = fs.readFileSync(process.argv[2], 'utf8');
for (const re of [
  /manufacturerCategoryId=(\d+)/g,
  /\{"id":(\d+),"name":"Plastic Model Kits"/g,
  /"manufacturerCategoryName":"Plastic Model Kits","manufacturerCategoryId":(\d+)/g,
  /"manufacturerCategoryId":(\d+),"manufacturerCategoryName":"Plastic Model Kits"/g,
]) {
  const hits = [...h.matchAll(re)].map((m) => m[1] || m[0].slice(0, 80));
  if (hits.length) console.log(String(re), hits.slice(0, 5));
}
