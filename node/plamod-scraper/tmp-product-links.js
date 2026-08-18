const fs = require('fs');
const h = fs.readFileSync(process.argv[2], 'utf8');
const links = [...h.matchAll(/href="(\/retailer\/products\/[^"]*manufacturerCategoryId=[^"]+)"/g)].map((m) => m[1]);
console.log('product links with mfr cat', links.length);
for (const link of links.slice(0, 8)) {
  console.log(link);
}

const pageUrl = h.match(/window\.location[^;]+|retailer\/manufacturers\/1\?[^"'\\s]+/g);
console.log('page urls', pageUrl ? pageUrl.slice(0, 5) : []);
