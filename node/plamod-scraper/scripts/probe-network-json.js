const fs = require('node:fs');

const path = process.argv[2];
const data = JSON.parse(fs.readFileSync(path, 'utf8'));
console.log('responses', data.length);

const interesting = data.filter((r) => /csv|export|download|preorder|search|product/i.test(r.url) || /SKU|Product Name|0225768/i.test(r.body_preview || ''));
interesting.slice(0, 40).forEach((r) => {
  console.log('\n---');
  console.log(r.status, r.url);
  console.log(r.content_type);
  if (r.body_preview) {
    console.log(r.body_preview.slice(0, 500));
  }
});
