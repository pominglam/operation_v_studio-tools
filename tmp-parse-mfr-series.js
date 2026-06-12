const fs = require('fs');
const path = require('path');

const htmlPath = path.join(
  __dirname,
  'storage/app/private/plamod/debug/manufacturer-1-export/20260608-141435-before-export.html',
);
const html = fs.readFileSync(htmlPath, 'utf8');

// Sidebar series rows often look like label text + badge counts in the HTML.
const seriesHits = new Map();
const re = />([^<]{3,80})<\/(?:span|label|div)[^>]*>[\s\S]{0,400}?>(\d{1,4})</g;
let m;
while ((m = re.exec(html)) !== null) {
  const name = m[1].replace(/\s+/g, ' ').trim();
  const count = Number.parseInt(m[2], 10);
  if (!name || Number.isNaN(count)) continue;
  if (/^(BRAND|CATEGORY|SERIES|Clear|In-Stock|Preorder|Out of Stock|Search|CSV)$/i.test(name)) continue;
  if (name.length < 4) continue;
  const prev = seriesHits.get(name) || { max: 0, total: 0 };
  prev.max = Math.max(prev.max, count);
  prev.total += count;
  seriesHits.set(name, prev);
}

const sorted = [...seriesHits.entries()]
  .filter(([name]) => /gundam|hguc|hg |mg |rg |macross|30m|keroro|armored|warhammer|seed|unicorn|witch|00|build|fighters|fantasy|sisters|missions|gquuu|victory|f91|crossbone|after colony|sanrio|pokemon|miku|idol/i.test(name) || name.includes('Gundam') || name.includes('HGUC') || name.includes('Plastic'))
  .sort((a, b) => b[1].max - a[1].max);

console.log('=== Likely gunpla-related sidebar labels (heuristic parse) ===');
for (const [name, stats] of sorted.slice(0, 60)) {
  console.log(`${String(stats.max).padStart(4)}  ${name}`);
}
