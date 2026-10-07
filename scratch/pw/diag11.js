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
    // Fetch ALL confirm movements for item 1 across pages
    let pageNo = 1, all = [], last = 1;
    do {
      const m = await j(`/api/items/1/movements?per_page=100&page=${pageNo}`);
      const rows = m.data || [];
      all = all.concat(rows);
      last = m.last_page || 1;
      pageNo++;
    } while (pageNo <= last && pageNo < 30);

    const confirms = all.filter(r => r.movement_type === 'confirm');
    const byRef = {};
    for (const c of confirms) {
      const k = c.ref_id;
      byRef[k] = (byRef[k] || 0) + 1;
    }
    const multi = Object.entries(byRef).filter(([k, v]) => v > 1).map(([k, v]) => ({ ref: k, confirms: v }));

    // Also bucket by time to see the flood window vs earlier
    const times = confirms.map(c => c.created_at).sort();
    return {
      totalRows: all.length,
      totalConfirms: confirms.length,
      earliest: confirms.length ? times[0] : null,
      latest: confirms.length ? times[times.length - 1] : null,
      ordersWithMultipleConfirms: multi,
      // first 40 rows overall (pre-flood?)
      sampleOldest: confirms.slice(0, 3).map(c => ({ id: c.id, ref: c.ref_id, at: c.created_at })),
    };
  }, { BASE });

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
