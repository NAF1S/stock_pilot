const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');

const EXE = 'C:/Users/Jubayer/AppData/Local/ms-playwright/chromium-1208/chrome-win64/chrome.exe';
const BASE = 'https://apigateway.live';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, storageState: path.join(__dirname, 'state.json') });
  const page = await ctx.newPage();
  page.on('response', (r) => { if (r.status() >= 500) console.log('[http ' + r.status() + ']', r.url()); });

  // Go to orders page
  await page.goto(BASE + '/orders', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3000);

  const targets = process.argv.slice(2).length ? process.argv.slice(2) : ['#1341', '#1333', '#1332', '#1314'];

  for (const t of targets) {
    try {
      // find the row containing the order number
      const row = page.locator('tr', { hasText: t }).first();
      await row.waitFor({ timeout: 10000 });
      const viewBtn = row.getByText('View', { exact: true }).first();
      await viewBtn.click();
      await page.waitForTimeout(2500);
      console.log('\n\n########## ORDER ' + t + ' @ ' + page.url() + ' ##########');
      const txt = await page.evaluate(() => document.body.innerText);
      console.log(txt.replace(/\n{2,}/g, '\n').slice(0, 3500));
      await page.goto(BASE + '/orders', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2500);
    } catch (e) {
      console.log('\n##### ' + t + ' ERROR: ' + e.message);
      await page.goto(BASE + '/orders', { waitUntil: 'domcontentloaded' }).catch(() => {});
      await page.waitForTimeout(2000);
    }
  }

  await browser.close();
})().catch((e) => { console.error('FATAL', e); process.exit(1); });
