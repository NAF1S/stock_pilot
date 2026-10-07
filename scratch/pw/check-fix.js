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

    // Find combo orders still in reserved/partial state (not yet confirmed)
    const orders = await j('/api/orders?per_page=100&reservation_state=reserved');
    const list = (orders.data || orders.data || []);

    // Also check the products list for reserved numbers on combos
    const prods = [];
    for (let pg = 1; pg <= 4; pg++) {
      const p = await j(`/api/products?per_page=100&page=${pg}`);
      prods.push(...(p.data || []));
    }

    // Look at the reserved quantities of the combo products
    const combos = prods.filter(x => /combo/i.test(x.name || '')).map(x => ({
      id: x.id, name: x.name, on_hand: x.quantity_on_hand, reserved: x.quantity_reserved, available: x.available,
    }));

    return { reservedOrders: list.slice(0, 15).map(o => ({ n: o.number, status: o.status, res: o.reservation_state, total: o.total })), combos };
  }, { BASE });

  console.log(JSON.stringify(out, null, 1).slice(0, 4000));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
