const { chromium } = require('playwright-core');
const path = require('path');

const EXE = 'C:/Users/Jubayer/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe';
const BASE = 'https://apigateway.live';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ storageState: path.join(__dirname, 'state.json') });
  const page = await ctx.newPage();
  await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);

  const out = await page.evaluate(async ({ BASE }) => {
    const j = async (p) => { const r = await fetch(BASE + p, { credentials: 'include' }); return (await r.json().catch(() => null)) || {}; };
    const result = [];
    for (const oid of [172, 163, 161, 145, 125, 209]) {
      const d = await j('/api/orders/' + oid);
      const od = d.order || {};
      const comps = (d.components || []).map(c => ({
        id: c.id, item: c.item_id, prod: c.component_product_id,
        orderItemId: c.order_item_id, status: c.status,
        total: c.total_quantity, reserved: c.reserved_quantity, confirmed: c.confirmed_quantity,
      }));
      // Detect duplicate (order_item_id, item/prod) pairs
      const seen = new Set();
      const dups = [];
      for (const c of comps) {
        const k = c.orderItemId + '|i' + (c.item ?? 0) + '|p' + (c.prod ?? 0);
        if (seen.has(k)) dups.push(k + ' (component id ' + c.id + ')');
        seen.add(k);
      }
      result.push({
        portalId: oid,
        number: od.number,
        status: od.status,
        res: od.reservation_state,
        sync: od.sync_status,
        componentCount: comps.length,
        duplicateKeys: dups,
        components: comps,
      });
    }
    return result;
  }, { BASE });

  console.log(JSON.stringify(out, null, 1).slice(0, 8000));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
