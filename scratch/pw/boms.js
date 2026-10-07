const { chromium } = require('playwright-core');
const path = require('path');

const EXE = 'C:/Users/Jubayer/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe';
const BASE = 'https://apigateway.live';

const TARGET_IDS = (process.argv.slice(2).length ? process.argv.slice(2) : []).map(Number);

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ storageState: path.join(__dirname, 'state.json') });
  const page = await ctx.newPage();
  await page.goto(BASE + '/orders', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);

  const out = await page.evaluate(async ({ BASE, TARGET_IDS }) => {
    const j = async (p) => (await fetch(BASE + p, { credentials: 'include' })).json();
    // Products list is paginated at 25/page; pull all pages.
    const all = [];
    let pageNum = 1, last = 1;
    do {
      const prods = await j('/api/products?per_page=100&page=' + pageNum);
      const rows = prods.data || prods;
      all.push(...rows);
      last = prods.last_page ?? pageNum;
      pageNum++;
    } while (pageNum <= last && pageNum < 20);
    const list = all;
    const picked = TARGET_IDS.length ? list.filter((p) => TARGET_IDS.includes(p.id)) : list;
    const result = [];
    for (const p of picked) {
      let bom = null;
      try { bom = await j('/api/products/' + p.id + '/bom'); } catch { bom = {}; }
      const raw = bom.bom || bom.lines || [];
      const lines = raw.map((l) => ({
        type: l.component_type || (l.component_product_id ? 'product' : 'item'),
        entity: l.component_product_id ? ('product#' + l.component_product_id + ' ' + (l.product_name ?? '?')) : ('item#' + l.item_id + ' ' + (l.item_name ?? '?')),
        qty: l.quantity,
      }));
      result.push({
        id: p.id,
        name: p.name,
        sku: p.sku ?? '',
        source: p.source,
        on_hand: p.quantity_on_hand,
        reserved: p.quantity_reserved,
        woo_id: p.woo_product_id ?? '',
        lines,
      });
    }
    return result;
  }, { BASE, TARGET_IDS });

  for (const p of out) {
    console.log('\n### PRODUCT #' + p.id + ' — ' + p.name + '  [sku=' + p.sku + '] on_hand=' + p.on_hand + ' reserved=' + p.reserved + ' source=' + p.source);
    if (!p.lines.length) { console.log('  (no recipe lines)'); continue; }
    for (const l of p.lines) console.log(`  ${l.type}\tqty=${l.qty}\t${l.entity}`);
  }
  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
