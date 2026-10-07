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
    const m = await j('/api/items/1/movements');
    const rows = (m.data || m) || [];
    return rows.map(r => ({ id: r.id, type: r.movement_type, qty: r.qty_delta, res: r.reserved_delta, ref_id: r.ref_id, at: r.created_at }));
  }, { BASE });

  console.log('newest 50 movements for Courier poly (item 1):');
  console.log(JSON.stringify(out, null, 0));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
