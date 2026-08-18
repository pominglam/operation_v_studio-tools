const fs = require('fs');
const h = fs.readFileSync(process.argv[2], 'utf8');
const re = /label for="(category|brand|series)-(\d+)"[^>]*title="([^"]+)"/g;
const map = new Map();
let m;
while ((m = re.exec(h)) !== null) {
  map.set(m[3], { id: m[2], prefix: m[1] });
}
console.log('count', map.size);
for (const name of ['Plastic Model Kits', 'HGUC', '30 Minutes Label', 'MG', 'Entry Grade']) {
  console.log(name, map.get(name));
}
