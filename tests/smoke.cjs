// AvilonX PHP: opens every page in a real browser against the PHP API and a
// MySQL/MariaDB database, signed in as each role through the sign-in page.
//   BASE=http://127.0.0.1:8090 node tests/smoke.cjs
// Needs a database with the test data from tests/seed.cjs (run that first).
const { chromium } = require('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const PAGES = {
  admin: ['admin/dashboard', 'admin/raceday', 'admin/members', 'admin/birds', 'admin/race-points', 'admin/coordinates', 'admin/races', 'admin/entries', 'admin/remarks', 'admin/clocking', 'admin/showup', 'admin/readers', 'admin/results', 'admin/history', 'admin/users', 'admin/settings'],
  member: ['member/dashboard', 'member/history', 'member/clocking', 'member/pigeons', 'member/coordinates', 'member/results', 'member/settings'],
  superadmin: ['superadmin/clubs', 'superadmin/stickers/register', 'superadmin/nfc', 'superadmin/readers', 'superadmin/stickers/generate', 'superadmin/setup'],
};
const ACCOUNTS = { superadmin: ['super', 'superpass1', 'team'], admin: ['admin', 'adminpass1', 'team'], member: ['ana', 'anapass12', 'member'] };
(async () => {
  const browser = await chromium.launch();
  const failures = [];
  for (const [role, list] of Object.entries(PAGES)) {
    const context = await browser.newContext({ viewport: { width: 1300, height: 900 } });
    await context.route(/^https:\/\/(?!cdn\.jsdelivr\.net)/, route => route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.dismiss().catch(() => {}));
    page.on('response', r => { if (r.url().includes('/api/') && r.status() >= 500) errors.push(`API ${r.status()} ${r.url()}`); });
    const [user, pass, tab] = ACCOUNTS[role];
    await page.goto(`${BASE}/pages/login/index.html`);
    await page.waitForSelector('#login-form');
    if (tab === 'member') await page.click('#audience-member');
    await page.fill('#login-form [name=username]', user);
    await page.fill('#login-form [name=password]', pass);
    await page.click('#login-form [type=submit]');
    await page.waitForURL(u => !String(u).includes('/login/'), { timeout: 10000 }).catch(() => failures.push(`${role}: sign-in did not leave the login page`));
    for (const name of list) {
      errors.length = 0;
      await page.goto(`${BASE}/pages/${name}/index.html`);
      await page.waitForFunction(() => (document.querySelector('.page-view')?.innerText.trim().length || 0) > 20, null, { timeout: 10000 }).catch(() => {});
      await page.waitForTimeout(500);
      const text = (await page.locator('.page-view').innerText().catch(() => '')).trim();
      const apiErrors = await page.evaluate(() => [...document.querySelectorAll('.notice.error, .toast.error')].filter(e => e.offsetParent).map(e => e.textContent.trim()).filter(Boolean));
      if (text.length <= 20) failures.push(`${name}: no content`);
      const real = errors.filter(e => !/AudioContext|vibrate/.test(e));
      if (real.length) failures.push(`${name}: ${real.join(' | ')}`);
      // A library from the internet (cdn.jsdelivr.net) that a test machine cannot reach is a warning, not a failure.
      const offline = apiErrors.filter(e => /internet connection/i.test(e));
      if (offline.length) console.log(`  ! ${name}: needs the internet for a library (${offline[0].slice(0, 80)})`);
      const shown = apiErrors.filter(e => !/internet connection/i.test(e));
      if (shown.length) failures.push(`${name}: shows error: ${shown.join(' | ').slice(0, 200)}`);
      console.log(`${failures.some(f => f.startsWith(name)) ? '✗' : '✓'} ${role} ${name}`);
    }
    await context.close();
  }
  await browser.close();
  if (failures.length) { console.log('\nFAILURES:\n' + failures.map(f => ' - ' + f).join('\n')); process.exit(1); }
  console.log('\nAll pages open.');
})();
