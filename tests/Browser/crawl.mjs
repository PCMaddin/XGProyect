/*
 * Opens every page of the game, the account, the admin area, the legal
 * pages and /operator in a real browser (Chromium, Playwright) and fails
 * when one shows a problem:
 *   - an error in the page's JavaScript, an HTTP error or a request that failed;
 *   - text that gives away a bug: an untranslated key (game/foo.bar), a
 *     placeholder left (%s, :attribute), an exception or a stack trace;
 *   - a marker from tests/Browser/seed.php that ran: names and texts that
 *     are code must stay text, also in tooltips (every onmouseover is fired);
 *   - on a phone (390 px): a main page wider than the screen, or a menu
 *     that does not open.
 *
 *   php tests/Browser/seed.php
 *   php artisan serve --port=8000 &
 *   node tests/Browser/crawl.mjs http://127.0.0.1:8000 en
 *
 * Screenshots of the pages with a problem go to tests/Browser/shots/.
 */
import { chromium } from 'playwright';
import { createHmac } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const base = (process.argv[2] || 'http://127.0.0.1:8000').replace(/\/$/, '');
const label = process.argv[3] || 'en';
const seed = JSON.parse(readFileSync(join(here, '.seed.json'), 'utf8'));
const shots = join(here, 'shots');
mkdirSync(shots, { recursive: true });

const GAME = [
  'overview', 'supplies', 'resourcesettings', 'facilities', 'research', 'shipyard', 'defenses', 'fleet1', 'movement',
  `galaxy&galaxy=${seed.galaxy}&system=${seed.system}`, 'empire', 'alliance', 'alliance&mode=memberslist', 'alliance&mode=admin&edit=members',
  'alliance&mode=admin&edit=requests', 'alliance&mode=admin&edit=ally', 'alliance&mode=admin&edit=class', `alliance&mode=ainfo&allyid=${seed.alliance}`,
  'buddies', 'messages', 'notices', 'highscore', 'highscore&who=2', 'highscore&who=1&type=6', 'highscore&who=1&type=9', 'search', 'preferences', 'premium',
  'darkmatter', 'changelog', 'technologytree', 'technologydetails&technology=1', 'technologydetails&technology=202', 'technologydetails&technology=219',
  'spacedock', 'traderOverview', 'traderResources', 'traderScrap', 'traderImportExport', 'traderAuctioneer', 'items', 'characterclass',
  'shortcuts', `chat&playerId=${seed.marker}`, `playerprofile&user=${seed.marker}`, 'phalanx', 'banned', 'officier',
].map((page) => '/game.php?page=' + page);
const ACCOUNT = ['/account/security', '/account/privacy', '/account/report?from=%2Fgame.php%3Fpage%3Doverview', '/account/wax', '/legal/imprint?lang=en', '/legal/imprint?lang=de'];
const ADMIN = [
  'admin', 'admin/home', 'admin/announcement', 'admin/search', 'admin/alliances', 'admin/alliances/create', `admin/alliances/${seed.alliance}`,
  `admin/alliances/${seed.alliance}/members`, `admin/alliances/${seed.alliance}/ranks`, 'admin/backup', 'admin/ban', 'admin/bots', 'admin/changelog',
  'admin/changelog/create', 'admin/errors', 'admin/expeditions', 'admin/fleets', 'admin/languages', 'admin/mailing', 'admin/messages', 'admin/modules',
  'admin/permissions', 'admin/planets', 'admin/premium', 'admin/premium/orders', 'admin/registration', 'admin/repair', 'admin/reset', 'admin/server',
  'admin/statistics', 'admin/suspicions', 'admin/tasks', 'admin/users', 'admin/users/create', `admin/users/${seed.marker}`, `admin/users/${seed.marker}/settings`,
  `admin/users/${seed.marker}/research`, `admin/users/${seed.marker}/premium`, `admin/users/${seed.marker}/planets`,
].map((path) => '/' + path);
const OPERATOR = ['/operator/universes', '/operator/errors', `/operator/errors/${seed.error}`, '/operator/reports', '/operator/legal', '/operator/legal/imprint/en', '/operator/backups', '/operator/account'];

// text that gives away a bug
const BAD = [
  /\b(game|admin|home|install|emails|account|operator)\/[a-z_]+\.[a-z]{2,3}_[a-z_0-9]+/,
  /%[sd]\b/, /%[0-9]%/, /:(attribute|user|alliance|technology|count|date|name)\b/,
  /ErrorException|Whoops|Stack trace|Undefined (array key|variable|index)|Call to a member|TypeError|SQLSTATE/,
];

// pages that show the error log: the text check would find the errors they list
const LOGS = ['/admin/errors', '/operator/errors', `/operator/errors/${seed.error}`];

const issues = [];
let current = '';
const browser = await chromium.launch();
const desktop = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await desktop.newPage();
page.on('pageerror', (error) => issues.push([current, 'JavaScript', error.message.slice(0, 200)]));
page.on('dialog', (dialog) => { issues.push([current, 'dialog', dialog.message().slice(0, 120)]); dialog.dismiss().catch(() => {}); });
page.on('response', (response) => {
  const url = response.url();
  if (response.status() >= 400 && url.startsWith(base)) issues.push([current, 'HTTP ' + response.status(), url.replace(base, '')]);
});
page.on('requestfailed', (request) => {
  const url = request.url();
  if (url.startsWith(base) && !url.endsWith('.mp4')) issues.push([current, 'request failed', url.replace(base, '')]);
});

async function visit(path) {
  current = path;
  const before = issues.length;
  await page.goto(base + path, { waitUntil: 'load' });
  await page.waitForLoadState('networkidle').catch(() => {});
  // the tooltips build their HTML when hovered
  await page.evaluate(() => document.querySelectorAll('[onmouseover]').forEach((element) => element.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }))));
  const found = await page.evaluate(() => ({
    ran: window.__xss || 0,
    img: [...document.querySelectorAll('img')].filter((img) => img.getAttribute('src') === 'x').length,
    text: document.body ? document.body.innerText : '',
  }));
  if (found.ran > 0) issues.push([path, 'XSS', `the marker ran ${found.ran} times`]);
  if (found.img > 0) issues.push([path, 'XSS', `${found.img} marker element(s) in the page`]);
  for (const pattern of LOGS.includes(path) ? [] : BAD) {
    const match = found.text.match(pattern);
    if (match) issues.push([path, 'text', match[0] + ' … ' + found.text.substr(Math.max(0, match.index - 60), 140).replace(/\s+/g, ' ')]);
  }
  if (issues.length > before) {
    await page.screenshot({ path: join(shots, `${label}-${path.replace(/[^a-z0-9]+/gi, '_').slice(0, 80)}.png`), fullPage: true }).catch(() => {});
  }
}

function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret.replace(/=+$/, '')) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
  const hmac = createHmac('sha1', key).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 0xf;
  return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

// the start page and the game
await visit('/');
current = 'login';
await Promise.all([
  page.waitForURL(/game\.php/),
  page.evaluate(([email, password]) => { const form = document.getElementById('loginForm'); form.username.value = email; form.password.value = password; form.submit(); }, [seed.admin.email, seed.admin.password]),
]);
for (const path of [...GAME, ...ACCOUNT]) await visit(path);

// the admin area
current = 'admin login';
await page.goto(base + '/admin');
if (await page.$('input[name=inputEmail]')) {
  await page.fill('input[name=inputEmail]', seed.admin.email);
  await page.fill('input[name=inputPassword]', seed.admin.password);
  await Promise.all([page.waitForNavigation(), page.click('button[name=signin]')]);
}
for (const path of ADMIN) await visit(path);

// the operators
current = 'operator login';
await page.goto(base + '/operator');
await page.fill('input[name=email]', seed.operator.email);
await page.fill('input[name=password]', seed.operator.password);
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
await page.fill('input[name=code]', totp(seed.operator.secret));
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
for (const path of OPERATOR) await visit(path);

// the main pages on a phone: nothing wider than the screen, the menu opens
const PHONE = [
  'overview', 'supplies', 'facilities', 'research', 'shipyard', 'defenses', 'fleet1', `galaxy&galaxy=${seed.galaxy}&system=${seed.system}`, 'empire',
  'messages', 'movement', 'alliance', 'alliance&mode=memberslist', 'buddies', 'resourcesettings', 'highscore', 'notices', 'search', 'preferences',
];
// these keep their columns and scroll sideways inside the page on purpose
const SCROLLS = ['galaxy', 'empire'];
const PHONE_SIZE = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };
let phone = await (await browser.newContext(PHONE_SIZE)).newPage();
async function fits(path) {
  current = 'phone ' + path;
  await phone.goto(base + path, { waitUntil: 'load' });
  const width = await phone.evaluate(() => Math.max(document.documentElement.scrollWidth, (document.getElementById('content') || document.body).scrollWidth));
  const scrolls = SCROLLS.some((name) => path.includes('page=' + name));
  if (width > 391 && !scrolls) {
    issues.push([current, 'phone', `${width} px wide`]);
    await phone.screenshot({ path: join(shots, `${label}-phone-${path.replace(/[^a-z0-9]+/gi, '_').slice(0, 60)}.png`), fullPage: true }).catch(() => {});
  }
}
await fits('/');
await fits('/account/recover');
// signed in as in the desktop browser (each sign-in counts against the limit)
phone = await (await browser.newContext({ ...PHONE_SIZE, storageState: await desktop.storageState() })).newPage();
for (const name of PHONE) await fits('/game.php?page=' + name);
const menuOpens = (await phone.isVisible('#menu-toggle'))
  && (await phone.tap('#menu-toggle', { timeout: 5000 }).then(() => true, () => false))
  && (await phone.isVisible('#leftmenu a[href*="page=galaxy"]'));
if (!menuOpens) issues.push(['phone menu', 'phone', 'no menu button, or the menu does not open']);

await browser.close();

const pages = 1 + GAME.length + ACCOUNT.length + ADMIN.length + OPERATOR.length + 2 + PHONE.length;
const unique = [...new Set(issues.map((issue) => issue.join(' | ')))];
for (const issue of unique) console.log(issue);
console.log(`${label}: ${pages} pages, ${unique.length} problems`);
process.exit(unique.length === 0 ? 0 : 1);
