const { chromium } = require('playwright-core');
const path = require('path');

const EXE = 'C:/Users/Jubayer/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe';
const BASE = 'https://apigateway.live';
const CUTOFF = '2026-08-07T20:08:00'; // just before the confirm burst

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ storageState: path.join(__dirname, 'state.json') });
  const page = await ctx.newPage();
  await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);

  const out = await page.evaluate(async ({ BASE, CUTOFF }) => {
    const j = async (p) => { const r = await fetch(BASE + p, { credentials: 'include' }); return (await r.json().catch(() => null)) || {}; };

    // 1) All products (current state)
    const prods = [];
    for (let pg = 1; pg <= 20; pg++) {
      const p = await j(`/api/products?per_page=100&page=${pg}`);
      const rows = p.data || [];
      prods.push(...rows);
      if (!p.next_page_url) break;
    }

    // 2) All order ids
    const orderIds = [];
    for (let pg = 1; pg <= 10; pg++) {
      const o = await j(`/api/orders?per_page=100&page=${pg}`);
      const rows = o.data || [];
      for (const r of rows) orderIds.push(r.id);
      if (!o.next_page_url) break;
    }

    // 3) Collect product movements from every order
    const byProduct = {}; // product_id -> [movements]
    for (const oid of orderIds) {
      const m = await j(`/api/orders/${oid}/movements`);
      let rows = (m.data || m.movements || m) || [];
      if (!Array.isArray(rows)) rows = [];
      for (const row of rows) {
        if (row.product_id == null) continue;
        (byProduct[row.product_id] = byProduct[row.product_id] || []).push({
          id: row.id, type: row.movement_type, qty: row.qty_delta,
          on_hand_after: row.on_hand_after, at: row.created_at,
        });
      }
    }

    // 4) For each product, find last pre-burst movement (on_hand_after) OR reconstruct
    const result = prods.map((pr) => {
      const movs = (byProduct[pr.id] || []).sort((a, b) => a.id - b.id);
      let lastPre = null;
      const burstSum = { qty: 0, first: null, last: null, count: 0 };
      for (const mv of movs) {
        if (mv.at < CUTOFF) { lastPre = mv; }
        else { burstSum.qty += Number(mv.qty || 0); burstSum.count++; if (!burstSum.first) burstSum.first = mv.at; burstSum.last = mv.at; }
      }
      let preOnHand;
      if (lastPre) preOnHand = lastPre.on_hand_after;
      else if (burstSum.count) preOnHand = pr.quantity_on_hand - burstSum.qty;
      else preOnHand = pr.quantity_on_hand; // no movements at all
      return {
        id: pr.id, name: pr.name, current: pr.quantity_on_hand, pre: preOnHand,
        lastPreType: lastPre ? lastPre.type : null, lastPreAt: lastPre ? lastPre.at : null,
        burstMovementCount: burstSum.count, burstWindow: burstSum.first ? (burstSum.first + ' -> ' + burstSum.last) : null,
      };
    });

    return result;
  }, { BASE, CUTOFF });

  console.log('PRODUCTS — pre-disaster vs current (cutoff ' + CUTOFF + '):');
  for (const r of out) {
    const delta = r.current - r.pre;
    console.log(`#${r.id} ${r.name}:  pre=${r.pre}  current=${r.current}  delta=${delta}  (lastPre=${r.lastPreType}@${r.lastPreAt ?? 'none'}, burstMoves=${r.burstMovementCount})`);
  }
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
