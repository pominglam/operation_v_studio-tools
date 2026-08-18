/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');

const envPath = path.resolve(__dirname, '..', '..', '.env');
if (fs.existsSync(envPath)) {
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) continue;
    const idx = trimmed.indexOf('=');
    const key = trimmed.slice(0, idx).trim();
    let value = trimmed.slice(idx + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (!process.env[key]) process.env[key] = value;
  }
}

const { ensureLoggedInQuick, ensureOnRetailerPdp } = require('./src/plamod');
const { restockAddLinesToCart } = require('./src/plamod-restock-cart');

async function main() {
  const result = await restockAddLinesToCart(
    { ensureLoggedInQuick, ensureOnRetailerPdp },
    {
      items: [
        { sku: '5058815', qty: 10, product_name: 'Action Base 4 Black', source: 'existing' },
        { sku: '5058009', qty: 10, product_name: 'Action Base 1/100 Black', source: 'existing' },
      ],
    },
  );

  console.log(JSON.stringify(result, null, 2));
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
