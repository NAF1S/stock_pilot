const { chromium } = require('playwright-core');
const path = require('path');

const EXE = 'C:/Users/Jubayer/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe';

const EMAIL = process.argv[2] || 'hidehaven.contact@gmail.com';
const PASS = process.argv[3] || '2026pass_nohack_hh';
const BASE = 'https://apigateway.live';

(async () => {
  const browser = await chromium.launch({
    executablePath: EXE,
    headless: true,
    args: ['--no-sandbox'],
  });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();

  page.on('console', (m) => { if (m.type() === 'error') console.log('[console.error]', m.text().slice(0, 200)); });
  page.on('response', (r) => {
    if (r.status() >= 500) console.log('[http ' + r.status() + ']', r.url());
  });

  // 1. Go to the app root; it will redirect to /login if unauthenticated.
  await page.goto(BASE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  console.log('landed at', page.url());
  await page.waitForTimeout(1500);

  // Fill login form
  try {
    await page.waitForSelector('input[type=email], input[name=email], input[type=text]', { timeout: 15000 });
    const emailInput = await page.$('input[type=email], input[name=email]');
    if (emailInput) await emailInput.fill(EMAIL);
    else await page.fill('input[type=text]', EMAIL);
    const passInput = await page.$('input[type=password], input[name=password]');
    if (passInput) await passInput.fill(PASS);
    console.log('filled login form');
    await page.click('button[type=submit], button:has-text("Log in"), button:has-text("Login"), button:has-text("Sign in")').catch(async () => {
      await page.keyboard.press('Enter');
    });
    await page.waitForTimeout(5000);
    console.log('after submit url:', page.url());
  } catch (e) {
    console.log('login form step error:', e.message);
    console.log('page url after error:', page.url());
    const body = await page.evaluate(() => document.body ? document.body.innerText.slice(0, 500) : 'no body');
    console.log('body:', body.replace(/\n+/g, ' | '));
  }

  // Save auth state for reuse
  await ctx.storageState({ path: path.join(__dirname, 'state.json') }).catch(() => {});
  await page.screenshot({ path: path.join(__dirname, '01-login.png'), fullPage: false });

  // 2. Go to orders subpage
  await page.goto(BASE + '/orders', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(4000);
  await page.screenshot({ path: path.join(__dirname, '02-orders.png'), fullPage: false });
  console.log('orders url:', page.url());
  const ordersText = await page.evaluate(() => document.body ? document.body.innerText.slice(0, 3000) : 'no body');
  console.log('--- ORDERS PAGE TEXT ---');
  console.log(ordersText.replace(/\n+/g, ' | '));

  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
