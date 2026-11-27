// Browser walkthrough of the Today page's "Logging for" day (headless Edge or Chrome, playwright-core).
// Same fresh database and served copy as tasks.test.mjs, with js/api.js pointing at the local API:
//   BASE_URL=http://127.0.0.1:8200 node tests/ui-today.mjs <screenshot folder>
import { chromium } from 'playwright-core';
import assert from 'node:assert/strict';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8200';
const SHOTS = process.argv[2];
const TOKEN = 'tok-dennis';
const browser = await chromium.launch({ executablePath: process.env.BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
const ctx = await browser.newContext({ viewport: { width: 1100, height: 900 } });
const results = [];
const consoleErrors = [];

function watch(page) {
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) { consoleErrors.push(`${page.url()}: ${m.text()}`); } });
  page.on('pageerror', e => consoleErrors.push(`${page.url()}: ${e.message}`));
  page.on('dialog', d => d.accept().catch(() => {}));
  return page;
}
async function step(name, fn) {
  try { await fn(); results.push(`PASS  ${name}`); } catch (e) {
    results.push(`FAIL  ${name} — ${e.message.split('\n').filter(Boolean).slice(0, 4).join(' | ')}`);
    if (SHOTS) { await page.screenshot({ path: `${SHOTS}/today-fail-${results.length}.png`, fullPage: true }).catch(() => {}); }
  }
}
async function api(action, { params, body } = {}) {
  const qs = new URLSearchParams({ action, ...(params || {}) });
  const res = await fetch(`${BASE}/api/api.php?${qs}`, {
    method: body ? 'POST' : 'GET',
    headers: { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json' },
    body: body ? JSON.stringify({ action, ...body }) : undefined,
  });
  return { status: res.status, data: await res.json().catch(() => null) };
}
const ymd = offset => {
  const d = new Date();
  d.setDate(d.getDate() + offset);
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};
const entriesOn = async day => (await api('activityLog', { params: { from: day, to: day } })).data.entries;
const TODAY = ymd(0);
const EARLIER = ymd(-3);
const EARLIER2 = ymd(-5);

const made = await api('addActivity', { body: { name: 'Piano practice', quickLog: true, typicalDurationMinutes: 15, active: true } });
assert.equal(made.status, 200, JSON.stringify(made.data));

let page = watch(await ctx.newPage());
await page.goto(`${BASE}/index.html?token=${TOKEN}`);
await page.locator('#log-day').waitFor();

await step('starts on today', async () => {
  assert.equal(await page.inputValue('#log-day'), TODAY);
  assert.equal(await page.inputValue('#f-date'), TODAY);
  assert.equal(await page.textContent('#log-day-label'), 'Today');
  assert.equal(await page.locator('#log-day-today').count(), 0);
  assert.equal(await page.textContent('#day-list-heading'), 'Today');
});

await step('pick an earlier day: banner, Back to today, form follows', async () => {
  await page.fill('#log-day', EARLIER);
  await page.locator('#log-day-today').waitFor();
  assert.notEqual(await page.textContent('#log-day-label'), 'Today');
  assert.equal(await page.inputValue('#f-date'), EARLIER);
  assert.match(await page.getAttribute('#day-card', 'class'), /other-day/);
  assert.notEqual(await page.textContent('#day-list-heading'), 'Today');
  await page.getByText('Nothing logged yet for this day').waitFor();
});

await step('Quick Log goes on the picked day', async () => {
  await page.click('[data-quick]');
  await page.locator('#today-list .log-row').first().waitFor();
  assert.equal((await entriesOn(EARLIER)).length, 1);
  assert.equal((await entriesOn(TODAY)).length, 0);
  assert.match(await page.textContent('#status'), /for /);
});

await step('a form entry goes on the picked day, and the next one starts there too', async () => {
  await page.selectOption('#f-activity', { label: 'Piano practice' });
  await page.fill('#f-duration', '30');
  await page.click('#log-form button[type="submit"]');
  await page.getByText('Logged.').waitFor();
  assert.equal((await entriesOn(EARLIER)).length, 2);
  assert.equal(await page.inputValue('#f-date'), EARLIER);
  assert.equal(await page.inputValue('#log-day'), EARLIER);
  assert.match(await page.textContent('[data-quick]'), /45 min/);
});

await step('still on that day after a reload', async () => {
  await page.reload();
  await page.locator('#log-day-today').waitFor();
  assert.equal(await page.inputValue('#log-day'), EARLIER);
  assert.equal(await page.inputValue('#f-date'), EARLIER);
  assert.match(await page.textContent('[data-quick]'), /45 min/);
});

await step("changing the form's Date moves the logged day, keeping what's typed", async () => {
  await page.selectOption('#f-activity', { label: 'Piano practice' });
  if (!(await page.isVisible('#f-notes'))) { await page.click('#f-more-toggle'); }
  await page.fill('#f-notes', 'scales');
  await page.fill('#f-date', EARLIER2);
  await page.waitForFunction(d => document.getElementById('log-day').value === d, EARLIER2);
  await page.getByText('Nothing logged yet for this day').waitFor();
  assert.equal(await page.inputValue('#f-notes'), 'scales');
  await page.click('#log-form button[type="submit"]');
  await page.getByText('Logged.').waitFor();
  assert.equal((await entriesOn(EARLIER2)).length, 1);
  assert.equal(await page.inputValue('#f-date'), EARLIER2);
});

await step('day type saves for the picked day, not today', async () => {
  assert.match(await page.textContent('#day-card'), /That day was a:/);
  await page.selectOption('#day-type', 'Travel');
  await page.click('#day-save');
  await page.getByText('Day status saved.').waitFor();
  assert.equal((await api('dayStatus', { params: { date: EARLIER2 } })).data.DayType, 'Travel');
  assert.equal((await api('dayStatus', { params: { date: TODAY } })).data.DayType, 'Home');
});

await step('Back to today', async () => {
  await page.click('#log-day-today');
  await page.waitForFunction(() => !document.getElementById('log-day-today'));
  assert.equal(await page.inputValue('#log-day'), TODAY);
  assert.equal(await page.inputValue('#f-date'), TODAY);
  assert.equal(await page.inputValue('#day-type'), 'Home');
  assert.match(await page.textContent('#day-card'), /Today is a:/);
  await page.click('[data-quick]');
  await page.getByText('Logged Piano practice.').waitFor();
  assert.equal((await entriesOn(TODAY)).length, 1);
});

await step('a new session starts on today again', async () => {
  await page.fill('#log-day', EARLIER);
  await page.locator('#log-day-today').waitFor();
  if (SHOTS) { await page.screenshot({ path: `${SHOTS}/today-other-day.png`, fullPage: false }); }
  const fresh = watch(await (await browser.newContext({ viewport: { width: 400, height: 800 } })).newPage());
  await fresh.goto(`${BASE}/index.html?token=${TOKEN}`);
  await fresh.locator('#log-day').waitFor();
  assert.equal(await fresh.inputValue('#log-day'), TODAY);
  assert.equal(await fresh.locator('#log-day-today').count(), 0);
});

await step('quickLog refuses a bad date', async () => {
  const id = made.data.id;
  const bad = await api('quickLog', { body: { activityId: id, date: '2026-02-30' } });
  assert.notEqual(bad.status, 200);
  const blank = await api('quickLog', { body: { activityId: id, date: '' } });
  assert.equal(blank.status, 200);
});

await browser.close();
console.log(results.join('\n'));
if (consoleErrors.length) { console.log('Console errors:\n' + consoleErrors.join('\n')); }
const failed = results.filter(r => r.startsWith('FAIL')).length;
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed || consoleErrors.length ? 1 : 0);
