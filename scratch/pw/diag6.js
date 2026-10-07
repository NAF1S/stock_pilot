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

    // Store sync counts — shows whether the portal is receiving a webhook flood
    const stores = await j('/api/stores');
    const storeList = (stores.body && stores.body.data) || (Array.isArray(stores.body) ? stores.body : []);
    const storeInfo = storeList.map(s => ({ id: s.id, name: s.name, sync_count: s.sync_count, last_sync_at: s.last_sync_at, last_seen_at: s.last_seen_at }));

    // Order #865 = portal id 209 (from movement reason)
    const detail = {};
    for (const oid of [209, 208, 206, 205, 172]) {
      const d = await j('/api/orders/' + oid);
      const od = d.body && (d.body.order || d.body);
      detail[oid] = {
        number: od ? od.number : '?',
        status: od ? od.status : '?',
        res: od ? od.reservation_state : '?',
        sync: od ? od.sync_status : '?',
        wooId: od ? od.woocommerce_id : '?',
        componentCount: od && od.components ? od.components.length : '?',
        components: od && od.components ? od.components.map(c => ({ entity: c.component_product ? ('p#' + c.component_product_id) : ('i#' + c.item_id), status: c.status, reserved: c.reserved_quantity, confirmed: c.confirmed_quantity, total: c.total_quantity })) : [],
        status_events: od && od.status_events ? od.status_events.slice(-6).map(e => `${e.status_from}->${e.status_to} @ ${e.occurred_at}`) : [],
      };
    }

    // How many confirm movements does order 209 have across the ledger? Use its item movements.
    return { storeInfo, detail };
  }, { BASE });

  console.log(JSON.stringify(out, null, 1).slice(0, 7000));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
