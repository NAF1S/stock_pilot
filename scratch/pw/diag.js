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
    const j = async (p) => { const r = await fetch(BASE + p, { credentials: 'include' }); return { status: r.status, body: await r.json().catch(() => null) }; };

    // 1) Orders summary (recent)
    const orders = await j('/api/orders?per_page=50');
    const list = (orders.body && orders.body.data) || [];
    const orderInfo = list.map(o => ({
      num: o.number, status: o.status, res: o.reservation_state, sync: o.sync_status, total: o.total,
    }));

    // 2) Item movements for Courier poly (item 1) and Belt Box (item 4), last 50 each
    const mov = {};
    for (const id of [1, 2, 3, 4, 5]) {
      const r = await j(`/api/items/${id}/movements`);
      const rows = (r.body && (r.body.data || r.body)) || [];
      // shape unknown — dump first row keys
      mov['item' + id] = {
        firstKeys: rows[0] ? Object.keys(rows[0]) : [],
        recent: rows.slice(0, 25).map(x => ({
          type: x.movement_type, qty: x.qty_delta, res: x.reserved_delta,
          ref: x.ref_type, ref_id: x.ref_id, reason: x.reason, at: x.created_at,
        })),
      };
    }
    return { orderInfo, mov, ordersStatus: orders.status };
  }, { BASE });

  console.log(JSON.stringify(out, null, 1).slice(0, 6000));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
