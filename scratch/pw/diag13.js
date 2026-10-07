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
    const items = await j('/api/items?per_page=200');
    const list = (items.data || items) || [];
    const q = {};
    for (const i of list) q[i.id] = { on_hand: i.quantity_on_hand, reserved: i.quantity_reserved };
    return { maxId: Math.max(...rows.map(r => r.id)), q };
  }, { BASE });

  const a = await snap();
  console.log('T0   maxId=' + a.maxId + ' poly.on_hand=' + (a.q[1] ? a.q[1].on_hand : '?') + ' box.on_hand=' + (a.q[4] ? a.q[4].on_hand : '?'));
  await new Promise(r => setTimeout(r, 60000));
  const b = await snap();
  console.log('T60  maxId=' + b.maxId + ' poly.on_hand=' + (b.q[1] ? b.q[1].on_hand : '?') + ' box.on_hand=' + (b.q[4] ? b.q[4].on_hand : '?'));
  await new Promise(r => setTimeout(r, 60000));
  const c = await snap();
  console.log('T120 maxId=' + c.maxId + ' poly.on_hand=' + (c.q[1] ? c.q[1].on_hand : '?') + ' box.on_hand=' + (c.q[4] ? c.q[4].on_hand : '?'));

  console.log('\nLedger grew T0->T120 by', c.maxId - a.maxId, '(0 = fully stopped)');

  // Sample statuses of recently-flooded orders
  const statuses = await page.evaluate(async ({ BASE }) => {
    const j = async (p) => { const r = await fetch(BASE + p, { credentials: 'include' }); return (await r.json().catch(() => null)) || {}; };
    const out = [];
    for (const oid of [254, 253, 251, 250, 249, 248, 245, 244, 243]) {
      const d = await j('/api/orders/' + oid);
      const od = d.order || {};
      out.push({ portalId: oid, number: od.number, status: od.status, res: od.reservation_state });
    }
    return out;
  }, { BASE });
  console.log('\nRecently-confirmed orders status:');
  for (const s of statuses) console.log(`  portal#${s.portalId} (woo #${s.number}): status=${s.status} reservation=${s.res}`);

  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
