const { chromium } = require('playwright-core');
const path = require('path');

const EXE = 'C:/Users/Jubayer/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe';
const BASE = 'https://apigateway.live';

const REFS = [24, 46, 47, 100, 125, 145, 161, 163, 172, 196, 412];

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ storageState: path.join(__dirname, 'state.json') });
  const page = await ctx.newPage();
  await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);

  const out = await page.evaluate(async ({ BASE, REFS }) => {
    const j = async (p) => { const r = await fetch(BASE + p, { credentials: 'include' }); return (await r.json().catch(() => null)) || {}; };

    // All confirm movements for item 1
    let pageNo = 1, all = [], last = 1;
    do {
      const m = await j(`/api/items/1/movements?per_page=100&page=${pageNo}`);
      all = all.concat(m.data || []);
      last = m.last_page || 1;
      pageNo++;
    } while (pageNo <= last && pageNo < 30);

    const byRef = {};
    for (const c of all.filter(r => r.movement_type === 'confirm')) {
      (byRef[c.ref_id] = byRef[c.ref_id] || []).push({ id: c.id, qty: c.qty_delta, at: c.created_at });
    }

    const result = [];
    for (const oid of REFS) {
      const d = await j('/api/orders/' + oid);
      const od = d.order || {};
      const items = d.items || [];
      // how many order items consume Courier poly (item 1)?
      const polyConsumers = items.filter(i => true).length; // every line may consume poly via recipe
      result.push({
        portalId: oid,
        number: od.number,
        status: od.status,
        res: od.reservation_state,
        sync: od.sync_status,
        lineItemCount: items.length,
        confirmsForPoly: (byRef[oid] || []),
      });
    }
    return result;
  }, { BASE, REFS });

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
