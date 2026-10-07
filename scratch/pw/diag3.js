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

  const snap = async () => {
    return page.evaluate(async ({ BASE }) => {
      const j = async (p) => { const r = await fetch(BASE + p, { credentials: 'include' }); return (await r.json().catch(() => null)) || []; };
      const items = await j('/api/items?per_page=200');
      const list = (items.data || items);
      const key = {};
      for (const i of list) key[i.id] = { name: i.name, on_hand: i.quantity_on_hand, reserved: i.quantity_reserved };
      return key;
    }, { BASE });
  };

  const a = await snap();
  console.log('--- SNAPSHOT A ---');
  for (const id of Object.keys(a)) console.log(`item#${id} ${a[id].name}: on_hand=${a[id].on_hand} reserved=${a[id].reserved}`);
  await new Promise(r => setTimeout(r, 30000));
  const b = await snap();
  console.log('\n--- SNAPSHOT B (30s later) — DELTA ---');
  let changed = false;
  for (const id of Object.keys(a)) {
    if (a[id].on_hand !== b[id].on_hand || a[id].reserved !== b[id].reserved) {
      changed = true;
      console.log(`item#${id} ${a[id].name}: on_hand ${a[id].on_hand} -> ${b[id].on_hand}  reserved ${a[id].reserved} -> ${b[id].reserved}`);
    }
  }
  if (!changed) console.log('(no items changed in 30s — stock is NOT actively moving)');
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
