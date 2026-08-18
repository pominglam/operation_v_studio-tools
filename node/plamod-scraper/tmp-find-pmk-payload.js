const fs = require('fs');
const text = fs.readFileSync(process.argv[2], 'utf8');
const idx = text.indexOf('Plastic Model Kits');
console.log('idx', idx);
if (idx >= 0) {
  console.log(text.slice(Math.max(0, idx - 120), idx + 120));
}

for (const re of [
  /\{"id":(\d+),"name":"Plastic Model Kits"/g,
  /"name":"Plastic Model Kits"[^}]*"id":(\d+)/g,
  /manufacturerCategoryId=(\d+)[^"]*"[^"]*Plastic Model Kits/g,
]) {
  let m;
  while ((m = re.exec(text)) !== null) {
    console.log('match', m[0].slice(0, 120));
  }
}
