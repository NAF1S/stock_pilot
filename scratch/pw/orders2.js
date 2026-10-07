const { chromium } = require('playwright-core');
const path = require('path');

const EXE = 'C:/Users/Jubayer/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe';
const BASE = 'https://apigateway.live';

const TARGET_NUMBERS = process.argv.slice(2).length ? process.argv.slice(2) : ['1341', '1333', '1332', '1314', '1312', '1344', '1343'];

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ storageState: path.join(__dirname, 'state.json') });
  const page = await ctx.newPage();
  await page.goto(BASE + '/orders', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2500);

  const result = await page.evaluate(async ({ BASE, TARGET_NUMBERS }) => {
    const j = async (p) => (await fetch(BASE + p, { credentials: 'include' })).json();
    const list = await j('/api/orders?per_page=500');
    const orders = (list.data || []).filter((o) => TARGET_NUMBERS.includes(o.number));
    const out = [];
    for (const o of orders) {
      const d = await j('/api/orders/' + o.id);
      out.push({
        id: o.id,
        number: o.number,
        status: o.status,
        reservation_state: o.reservation_state,
        sync_status: o.sync_status,
        total: o.total,
        customer: o.customer_name,
        items: d.items.map((i) => ({ name: i.product_name, sku: i.sku, qty: i.quantity, price: i.price, line_total: i.line_total })),
        components: d.components.map((c) => ({
          entity: c.component_product ? ('product#' + c.component_product_id + ' ' + c.component_product.name) : ('item#' + c.item_id + ' ' + (c.item ? c.item.name : '?')),
          per_unit: c.quantity_per_unit,
          order_qty: c.order_quantity,
          total_quantity: c.total_quantity,
          reserved_quantity: c.reserved_quantity,
          confirmed_quantity: c.confirmed_quantity,
          status: c.status,
        })),
      });
    }
    return out;
  }, { BASE, TARGET_NUMBERS });

  for (const o of result) {
    console.log('\n\n########## ORDER #' + o.number + '  (' + o.customer + ') ' + o.status + ' / ' + o.reservation_state + ' / sync=' + o.sync_status + '  total=' + o.total + ' ##########');
    console.log('-- ORDERED ITEMS --');
    for (const i of o.items) console.log(`  ${i.qty} x ${i.name}  (sku=${i.sku ?? ''}, price=${i.price}, line=${i.line_total})`);
    console.log('-- COMPONENTS RESERVED --');
    for (const c of o.components) console.log(`  perUnit=${c.per_unit} orderQty=${c.order_qty} TOTAL=${c.total_quantity}  reserved=${c.reserved_quantity} confirmed=${c.confirmed_quantity}  [${c.status}]  ${c.entity}`);
  }
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
