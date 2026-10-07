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

    const result = {};
    // Movements for a few items
    for (const id of [1, 2, 3, 4, 5, 7, 8]) {
      const r = await j(`/api/items/${id}/movements`);
      let rows = (r.body && (r.body.data || r.body)) || [];
      if (!Array.isArray(rows)) rows = [];
      const confirms = rows.filter(x => x.movement_type === 'confirm');
      const byOrder = {};
      for (const c of confirms) { const k = (c.ref_type || '?') + '/' + (c.ref_id ?? '?'); byOrder[k] = (byOrder[k] || 0) + 1; }
      result['item' + id] = {
        status: r.status,
        totalRows: rows.length,
        confirms: confirms.length,
        byOrder,
        recent3: rows.slice(0, 3).map(x => ({ type: x.movement_type, qty: x.qty_delta, res: x.reserved_delta, ref: x.ref_type, ref_id: x.ref_id, at: x.created_at, reason: x.reason })),
      };
    }
    return result;
  }, { BASE });

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
