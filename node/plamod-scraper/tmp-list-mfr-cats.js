const fs = require('fs');
const t = fs.readFileSync(process.argv[2], 'utf8');
const re = /manufacturerCategoryId":(\d+),"manufacturerCategoryName":"((?:\\.|[^"\\])*)"/g;
const seen = new Set();
let m;
while ((m = re.exec(t)) !== null) {
  const name = m[2].replace(/\\"/g, '"');
  const key = `${m[1]}:${name}`;
  if (seen.has(key)) {
    continue;
  }
  seen.add(key);
  if (/plastic model kits|HGUC|entry grade|30 minutes|^MG$|SD /i.test(name)) {
    console.log(m[1], name);
  }
}
