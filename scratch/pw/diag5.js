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

  const snap = async () => page.evaluate(async ({ BASE }) => {
    const j = async (p) => { const r = await fetch(BASE + p, { credentials: 'include' }); return (await r.json().catch(() => null)) || {}; };
    const m = await j('/api/items/1/movements');
    const rows = (m.data || m) || [];
    const ids = rows.map(r => r.id);
    const items = await j('/api/items?per_page=200');
    const item1 = ((items.data || items) || []).find(i => i.id === 1);
    return {
      maxId: ids.length ? Math.max(...ids) : null,
      minId: ids.length ? Math.min(...ids) : null,
      count: rows.length,
      onHand: item1 ? item1.quantity_on_hand : null,
      serverNow: item1 ? item1.created_at : null,
    };
  }, { BASE });

  const a = await snap();
  console.log('T0 :', JSON.stringify(a));
  await new Promise(r => setTimeout(r, 45000));
  const b = await snap();
  console.log('T45:', JSON.stringify(b));
  await new Promise(r => setTimeout(r, 45000));
  const c = await snap();
  console.log('T90:', JSON.stringify(c));

  if (a.maxId !== null && b.maxId !== null) {
    console.log('\nmax movement id grew T0->T45 by', b.maxId - a.maxId);
    console.log('max movement id grew T45->T90 by', c.maxId - b.maxId);
  }
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
