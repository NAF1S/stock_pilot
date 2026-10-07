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

    const items = await j('/api/items?per_page=200');
    const list = (items.data || items) || [];

    const result = [];
    for (const it of list) {
      // fetch all movements
      let pg = 1, all = [], last = 1;
      do {
        const m = await j(`/api/items/${it.id}/movements?per_page=100&page=${pg}`);
        const rows = m.data || [];
        all = all.concat(rows);
        last = m.last_page || 1;
        pg++;
      } while (pg <= last && pg < 30);

      all.sort((a, b) => (a.id - b.id));

      // Find the last movement before the force-re-sync burst.
      // The burst = rapid confirmations starting ~2026-08-07T20:00Z.
      let pre = null;
      let burstStartId = null;
      for (let i = 0; i < all.length; i++) {
        const r = all[i];
        const t = (r.created_at || '');
        if (t < '2026-08-07T19:00:00') {
          pre = r; // last movement strictly before the burst window
        } else if (burstStartId === null && r.movement_type === 'confirm' && t >= '2026-08-07T19:00:00') {
          burstStartId = r.id;
        }
      }

      result.push({
        id: it.id,
        name: it.name,
        current_on_hand: it.quantity_on_hand,
        current_reserved: it.quantity_reserved,
        // last movement before the burst window
        pre_movement: pre ? { id: pre.id, type: pre.movement_type, qty: pre.qty_delta, on_hand_after: pre.on_hand_after, reserved_after: pre.reserved_after, at: pre.created_at } : null,
        burst_start_movement_id: burstStartId,
        movementCount: all.length,
      });
    }
    return result;
  }, { BASE });

  for (const r of out) {
    const pre = r.pre_movement;
    console.log(`item#${r.id} ${r.name}`);
    console.log(`  CURRENT  on_hand=${r.current_on_hand}  reserved=${r.current_reserved}`);
    if (pre) {
      console.log(`  PRE-DISASTER (last ledger row before burst, id=${pre.id} ${pre.type} @${pre.at}): on_hand=${pre.on_hand_after} reserved=${pre.reserved_after}`);
    } else {
      console.log(`  (no ledger row before the burst — item has no pre-disaster ledger) burst first confirm id=${r.burst_start_movement_id}`);
    }
    console.log('');
  }
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
