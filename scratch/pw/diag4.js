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

    const r = await j('/api/items/1/movements');
    const rows = (r.body && (r.body.data || r.body)) || [];
    const newest = rows.slice(0, 12).map(x => ({
      id: x.id, type: x.movement_type, qty: x.qty_delta, res: x.reserved_delta,
      ref_id: x.ref_id, reason: x.reason, at: x.created_at, reversal_of: x.reversal_of,
    }));

    // Order details for the refs we just saw
    const orderIds = [...new Set(rows.slice(0, 12).map(x => x.ref_id).filter(Boolean))];
    const orders = [];
    for (const oid of orderIds) {
      const d = await j('/api/orders/' + oid);
      const od = d.body && (d.body.order || d.body);
      orders.push({
        portalId: oid,
        number: od ? od.number : '?',
        status: od ? od.status : '?',
        res: od ? od.reservation_state : '?',
        sync: od ? od.sync_status : '?',
        wooId: od ? od.woocommerce_id : '?',
      });
    }
    return { newest, orders, movementsStatus: r.status };
  }, { BASE });

  console.log(JSON.stringify(out, null, 1).slice(0, 4000));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
