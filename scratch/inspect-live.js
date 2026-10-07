// Inspect live StockPilot production data to diagnose the 3x combo reservation bug.
const BASE = 'https://apigateway.live';

let cookieJar = {};

function parseSetCookies(headers) {
  const raw = headers.getSetCookie ? headers.getSetCookie() : [];
  for (const sc of raw) {
    const [pair] = sc.split(';');
    const eq = pair.indexOf('=');
    if (eq === -1) continue;
    const name = pair.slice(0, eq).trim();
    const val = pair.slice(eq + 1).trim();
    cookieJar[name] = val;
  }
}

function cookieHeader() {
  return Object.entries(cookieJar).map(([k, v]) => `${k}=${v}`).join('; ');
}

async function api(path, opts = {}) {
  const headers = { Accept: 'application/json', ...(opts.headers || {}) };
  const cookie = cookieHeader();
  if (cookie) headers.Cookie = cookie;
  if (opts.method && opts.method !== 'GET' && !headers['X-XSRF-TOKEN'] && cookieJar['XSRF-TOKEN']) {
    headers['X-XSRF-TOKEN'] = decodeURIComponent(cookieJar['XSRF-TOKEN']);
    headers['Content-Type'] = 'application/json';
  }
  const res = await fetch(BASE + path, { method: opts.method || 'GET', headers, body: opts.body ? JSON.stringify(opts.body) : undefined, redirect: 'follow' });
  parseSetCookies(res.headers);
  let json = null;
  try { json = await res.json(); } catch { /* non-json */ }
  return { status: res.status, json };
}

async function main() {
  const email = process.argv[2] || 'hidehaven.contact@gmail.com';
  const password = process.argv[3] || '2026pass_nohack_hh';

  // Step 1: CSRF cookie
  await fetch(BASE + '/sanctum/csrf-cookie', { headers: { Accept: 'application/json' } }).then(r => { parseSetCookies(r.headers); return r.text(); }).catch(e => console.error('csrf fetch err', e.message));
  await new Promise(r => setTimeout(r, 500));

  // Step 2: login
  const login = await api('/api/auth/login', { method: 'POST', body: { email, password } });
  console.log('LOGIN', login.status, login.json && login.json.user ? login.json.user.name : (login.json && login.json.message) || login.json);

  if (login.status !== 200) { console.error('login failed'); return; }

  // Step 3: products list
  const prods = await api('/api/products');
  const list = prods.json && (prods.json.data || prods.json);
  console.log('\n=== PRODUCTS ===');
  for (const p of (Array.isArray(list) ? list : [])) {
    console.log(`#${p.id}  ${p.name}  [sku=${p.sku ?? '-'}]  on_hand=${p.quantity_on_hand} reserved=${p.quantity_reserved} source=${p.source} woo_id=${p.woo_product_id ?? '-'} hasBom=${p.has_bom ?? '?'}`);
  }
}

main().catch(e => { console.error('FATAL', e); process.exit(1); });
