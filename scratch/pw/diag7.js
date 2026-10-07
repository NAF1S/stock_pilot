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

    // Raw stores response
    const stores = await j('/api/stores');

    // Order 209 detail — components + status_events from the correct keys
    const d = await j('/api/orders/209');
    const od = d.body || {};
    const order209 = {
      keys: Object.keys(od),
      order: od.order ? { number: od.order.number, status: od.order.status, res: od.order.reservation_state, sync: od.order.sync_status, last_evt: od.order.last_webhook_event_id, updated_at: od.order.updated_at } : null,
      components: (od.components || []).map(c => ({ id: c.id, item: c.item_id, prod: c.component_product_id, status: c.status, total: c.total_quantity, reserved: c.reserved_quantity, confirmed: c.confirmed_quantity })),
      status_events: (od.status_events || []).slice(-8).map(e => `${e.status_from ?? '-'}->${e.status_to} @${e.occurred_at}`),
    };

    // movements for order 209
    const mov = await j('/api/orders/209/movements');
    let movRows = (mov.body && (mov.body.data || mov.body)) || [];
    if (!Array.isArray(movRows)) movRows = [];
    const orderMovements = {
      rawType: typeof mov.body,
      count: movRows.length,
      types: movRows.slice(0, 20).map(m => ({ type: m.movement_type, qty: m.qty_delta, item: m.item_id, prod: m.product_id, at: m.created_at })),
    };

    return { stores: { status: stores.status, body: stores.body }, order209, orderMovements };
  }, { BASE });

  console.log(JSON.stringify(out, null, 1).slice(0, 6000));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
