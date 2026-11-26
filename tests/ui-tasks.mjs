// Browser walkthrough of Tasks and the Wall (headless Edge or Chrome, playwright-core).
// Same database and served copy as tasks.test.mjs (fresh), with js/api.js pointing at the
// local API:  BASE_URL=http://127.0.0.1:8200 node tests/ui-tasks.mjs <screenshot folder>
import { chromium } from 'playwright-core';
import assert from 'node:assert/strict';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8200';
const SHOTS = process.argv[2];
const browser = await chromium.launch({ executablePath: process.env.BROWSER || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
const results = [];
const consoleErrors = [];

async function newPage(viewport, token) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  // Refused requests (the empty-task and unknown-email checks) are expected; real errors are not.
  page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) { consoleErrors.push(`${page.url()}: ${m.text()}`); } });
  page.on('pageerror', e => consoleErrors.push(`${page.url()}: ${e.message}`));
  page.on('dialog', d => d.accept(d.type() === 'prompt' ? (page.nextPrompt ?? d.defaultValue()) : undefined).catch(() => {}));
  await page.goto(`${BASE}/tasks.html?token=${token}`);
  return page;
}
async function step(name, fn) {
  try { await fn(); results.push(`PASS  ${name}`); } catch (e) {
    results.push(`FAIL  ${name} — ${e.message.split('\n').filter(Boolean).slice(0, 4).join(' | ')}`);
    if (SHOTS) { await desk.screenshot({ path: `${SHOTS}/fail-${results.length}.png`, fullPage: true }).catch(() => {}); }
  }
}
// The .task block whose own row (not a nested sub-task's) reads `what`.
const row = (page, what) => page.locator(`xpath=//div[contains(concat(' ', @class, ' '), ' task ')][div[contains(@class, 'task-row')]/div[@class='t-body']/div[@class='t-what'][starts-with(normalize-space(.), "${what}")]]`);
async function act(page, what, action) {
  // ⋯ toggles; after adding a sub-task the bar is still open, ready for another.
  if (!(await row(page, what).locator(':scope > .t-actions').count())) {
    await row(page, what).locator(':scope > .task-row [data-more]').click();
  }
  await row(page, what).locator(`:scope > .t-actions [data-act="${action}"]`).click();
}
async function addSub(page, parent, what) {
  await act(page, parent, 'sub');
  await page.fill('#f-what', what);
  await page.locator('form[data-form="sub"] button[type="submit"]').click();
  await row(page, what).waitFor();
}

const desk = await newPage({ width: 1280, height: 900 }, 'tok-dennis');
let listUrl = '';

await step('Tasks is in the menu; make a list', async () => {
  await desk.goto(`${BASE}/index.html`);
  await desk.click('nav >> text=Tasks');
  await desk.fill('#new-list-name', 'Christmas prep');
  await desk.click('#new-list button[type="submit"]');
  await desk.getByRole('heading', { name: 'Christmas prep' }).waitFor();
  listUrl = desk.url();
});

await step('add tasks with details, then children and a grandchild', async () => {
  for (const what of ['Decorate', 'Buy gifts', 'Bake cookies', 'Send cards']) {
    await desk.fill('#a-what', what);
    await desk.press('#a-what', 'Enter');
    await row(desk, what).waitFor();
  }
  await desk.click('#a-more');
  await desk.fill('#a-what', 'Mail packages');
  await desk.fill('#a-date', '2020-12-10');
  await desk.fill('#a-who', 'Karen');
  await desk.click('#add-task button[type="submit"]');
  await row(desk, 'Mail packages').locator('.due-over').waitFor();
  await addSub(desk, 'Decorate', 'Tree');
  await addSub(desk, 'Tree', 'Lights');
  await addSub(desk, 'Tree', 'Ornaments');
  await addSub(desk, 'Buy gifts', 'Grandkids');
  assert.equal(await row(desk, 'Lights').getAttribute('class'), 'task depth-2');
  assert.equal(await row(desk, 'Lights').locator('[data-act]').count(), 0);
  await act(desk, 'Lights', 'edit');
  assert.equal(await desk.locator('[data-act="sub"]').count(), 0, 'a grandchild has no "+ Sub-task"');
  await desk.click('[data-cancel]');
  await desk.screenshot({ path: `${SHOTS}/t1-list.png`, fullPage: true });
});

await step('empty task is refused with a message', async () => {
  await desk.fill('#a-what', '');
  await desk.click('#add-task button[type="submit"]');
  await desk.locator('#a-err', { hasText: 'Say what the task is' }).waitFor();
});

await step('collapse a branch; it stays collapsed after reload', async () => {
  await row(desk, 'Decorate').locator(':scope > .task-row [data-fold]').click();
  assert.equal(await desk.locator('.t-what', { hasText: 'Tree' }).count(), 0);
  await desk.reload();
  await row(desk, 'Decorate').waitFor();
  assert.equal(await desk.locator('.t-what', { hasText: 'Tree' }).count(), 0);
  await row(desk, 'Decorate').locator(':scope > .task-row [data-fold]').click();
  await row(desk, 'Tree').waitFor();
});

await step('explode to the Wall: one tile per top-level task, in list order, sub-tasks inside', async () => {
  await desk.click('text=Explode to the Wall');
  await desk.locator('.tile').first().waitFor();
  const titles = await desk.locator('.tile .tw').allTextContents();
  assert.deepEqual(titles, ['Decorate', 'Buy gifts', 'Bake cookies', 'Send cards', 'Mail packages']);
  const subs = await desk.locator('.tile').first().locator('.tile-sub .sw').allTextContents();
  assert.deepEqual(subs, ['Tree', 'Lights', 'Ornaments']);
  assert.equal(await desk.locator('.tile-sub.gc').count(), 2);
  assert.ok(await desk.locator('.tile-wrap.over').count() === 1, 'overdue tile marked');
  await desk.screenshot({ path: `${SHOTS}/t2-wall.png`, fullPage: true });
});

await step('tap a tile to check it — it stays put, dimmed with a ✓; tap again to uncheck', async () => {
  await desk.click('.tile-main:has-text("Bake cookies")');
  const tile = desk.locator('.tile-wrap', { hasText: 'Bake cookies' });
  await tile.locator('.check').waitFor();
  assert.match(await tile.getAttribute('class'), /done/);
  assert.deepEqual(await desk.locator('.tile .tw').allTextContents(), ['Decorate', 'Buy gifts', 'Bake cookies', 'Send cards', 'Mail packages']);
  await desk.click('.tile-sub:has-text("Lights")');
  await desk.locator('.tile-sub[aria-pressed="true"]', { hasText: 'Lights' }).waitFor();
  await desk.locator('.progress-line', { hasText: '2 of 9 done' }).waitFor();
  await desk.reload();
  await desk.locator('.tile.done', { hasText: 'Bake cookies' }).waitFor();
  await desk.click('.tile-main:has-text("Bake cookies")');
  await desk.locator('.progress-line', { hasText: '1 of 9 done' }).waitFor();
  await desk.screenshot({ path: `${SHOTS}/t3-wall-checked.png`, fullPage: true });
});

await step('tiles move only by moving the task in the list', async () => {
  await desk.click('text=Back to the list');
  await act(desk, 'Send cards', 'up');
  await desk.locator('#tree > .task:nth-child(3) > .task-row .t-what', { hasText: 'Send cards' }).waitFor();
  await desk.goto(`${listUrl}&wall=1`);
  await desk.locator('.tile').first().waitFor();
  assert.deepEqual(await desk.locator('.tile .tw').allTextContents(), ['Decorate', 'Buy gifts', 'Send cards', 'Bake cookies', 'Mail packages']);
});

await step('indent and outdent from the list', async () => {
  await desk.goto(listUrl);
  await act(desk, 'Bake cookies', 'indent');
  await desk.locator('.task.depth-1', { hasText: 'Bake cookies' }).waitFor();
  await act(desk, 'Bake cookies', 'outdent');
  await desk.locator('.task.depth-1', { hasText: 'Bake cookies' }).waitFor({ state: 'detached' });
  await desk.locator('#tree > .task', { hasText: 'Bake cookies' }).waitFor();
});

await step('share with Karen; she sees it, checks a tile, and Dennis\'s open Wall updates on its own', async () => {
  await desk.click('#people-btn');
  await desk.fill('#share-email', 'nobody@test');
  await desk.click('#share-form button[type="submit"]');
  await desk.locator('#share-err', { hasText: 'No Life Tempo user' }).waitFor();
  await desk.fill('#share-email', 'karen@test');
  await desk.click('#share-form button[type="submit"]');
  await desk.locator('.member-row', { hasText: 'Karen' }).waitFor();
  await desk.goto(`${listUrl}&wall=1`);
  await desk.locator('.tile').first().waitFor();

  const karen = await newPage({ width: 1280, height: 900 }, 'tok-karen');
  await karen.locator('.list-card', { hasText: 'Shared by Dennis' }).click();
  await karen.getByRole('heading', { name: 'Christmas prep' }).waitFor();
  assert.equal(await karen.locator('#rename-btn').count(), 0, 'only the owner renames');
  await karen.click('text=Explode to the Wall');
  await karen.click('.tile-main:has-text("Send cards")');
  await karen.locator('.tile.done', { hasText: 'Send cards' }).waitFor();
  await desk.locator('.tile.done', { hasText: 'Send cards' }).waitFor({ timeout: 15000 });
  await karen.context().close();
});

await step('clear checked hides them on the list and Wall; restore brings them back', async () => {
  await desk.goto(listUrl);
  await desk.click('#clear-btn');
  await desk.locator('#restore-btn').waitFor();
  assert.equal(await desk.locator('.t-what', { hasText: 'Send cards' }).count(), 0);
  await desk.goto(`${listUrl}&wall=1`);
  await desk.locator('.tile').first().waitFor();
  assert.equal(await desk.locator('.tile', { hasText: 'Send cards' }).count(), 0);
  await desk.goto(listUrl);
  await desk.click('#restore-btn');
  await row(desk, 'Send cards').waitFor();
});

await step('contact: name and phone on the list and the Wall, with Call and Text', async () => {
  await desk.goto(listUrl);
  await act(desk, 'Mail packages', 'edit');
  await desk.fill('#f-cname', 'Post office');
  await desk.fill('#f-cphone', 'not a number');
  await desk.locator('form[data-form="edit"] button[type="submit"]').click();
  await desk.locator('form[data-form="edit"] .err', { hasText: 'phone number' }).waitFor();
  await desk.fill('#f-cphone', '(555) 123-4567 x12');
  await desk.locator('form[data-form="edit"] button[type="submit"]').click();
  const c = row(desk, 'Mail packages').locator(':scope > .task-row .t-contact');
  await c.waitFor();
  assert.equal(await c.locator('a', { hasText: 'Call' }).getAttribute('href'), 'tel:5551234567,12');
  assert.equal(await c.locator('a', { hasText: 'Text' }).getAttribute('href'), 'sms:5551234567');
  await desk.goto(`${listUrl}&wall=1`);
  const tc = desk.locator('.tile-wrap', { hasText: 'Mail packages' }).locator('.tile-contact');
  await tc.waitFor();
  assert.match(await tc.textContent(), /Post office/);
  // Tapping Call/Text on a tile must not check the tile.
  await tc.locator('a', { hasText: 'Text' }).evaluate(a => { a.addEventListener('click', e => e.preventDefault()); a.click(); });
  assert.equal(await desk.locator('.tile-wrap.done', { hasText: 'Mail packages' }).count(), 0);
  await desk.screenshot({ path: `${SHOTS}/t6-wall-contact.png`, fullPage: true });
});

await step('add to Plan: date and time filled from the task; it shows on the Plan page', async () => {
  await desk.goto(listUrl);
  await act(desk, 'Bake cookies', 'plan');
  assert.equal(await desk.inputValue('#p-date'), await desk.evaluate(() => todayIso()), 'no date on the task: today');
  await desk.locator('form[data-form="plan"] button[type="submit"]').click();
  await desk.locator('form[data-form="plan"] .err', { hasText: 'start time' }).waitFor();
  await desk.fill('#p-date', '2026-12-20');
  await desk.fill('#p-time', '14:00');
  await desk.fill('#p-end', '16:00');
  await desk.screenshot({ path: `${SHOTS}/t7-add-to-plan.png`, fullPage: true });
  await desk.locator('form[data-form="plan"] button[type="submit"]').click();
  await desk.locator('#status', { hasText: 'is on your Plan' }).waitFor();
  assert.equal(await desk.locator('form[data-form="plan"]').count(), 0);
  // The same again is refused.
  await act(desk, 'Bake cookies', 'plan');
  await desk.fill('#p-date', '2026-12-20');
  await desk.fill('#p-time', '14:00');
  await desk.locator('form[data-form="plan"] button[type="submit"]').click();
  await desk.locator('form[data-form="plan"] .err', { hasText: 'already on your Plan' }).waitFor();
  await desk.click('form[data-form="plan"] [data-cancel]');
  await desk.goto(`${BASE}/plan.html`);
  await desk.locator('text=Bake cookies').first().waitFor();
});

await step('print: every branch opens, buttons and forms hide, then it goes back', async () => {
  await desk.goto(listUrl);
  await row(desk, 'Decorate').locator(':scope > .task-row [data-fold]').click();
  assert.equal(await desk.locator('.t-what', { hasText: 'Lights' }).count(), 0);
  await desk.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
  await desk.emulateMedia({ media: 'print' });
  await row(desk, 'Lights').waitFor();
  assert.equal(await desk.locator('#add-task').isVisible(), false);
  assert.equal(await desk.locator('.t-more').first().isVisible(), false);
  assert.equal(await desk.locator('.call-btn').first().isVisible(), false);
  assert.ok(await desk.locator('.print-only', { hasText: 'Printed' }).isVisible());
  assert.ok(await desk.locator('.t-contact a.num').isVisible(), 'the phone number itself still prints');
  assert.equal(await desk.locator('.btn.accent', { hasText: 'Explode' }).isVisible(), false);
  if (SHOTS) { await desk.screenshot({ path: `${SHOTS}/t8-print.png`, fullPage: true }); }
  await desk.emulateMedia({ media: 'screen' });
  await desk.evaluate(() => window.dispatchEvent(new Event('afterprint')));
  assert.equal(await desk.locator('.t-what', { hasText: 'Lights' }).count(), 0, 'back to collapsed');
  await row(desk, 'Decorate').locator(':scope > .task-row [data-fold]').click();
  await row(desk, 'Lights').waitFor();
});

await step('phone: the Wall is two tiles across with no sideways scrolling', async () => {
  const phone = await newPage({ width: 390, height: 844 }, 'tok-dennis');
  await phone.goto(`${listUrl}&wall=1`);
  await phone.locator('.tile').first().waitFor();
  // Tiles are tilted a little, so compare their tops loosely.
  const boxes = await phone.locator('.tile').evaluateAll(ts => ts.slice(0, 2).map(t => t.getBoundingClientRect().top));
  assert.ok(Math.abs(boxes[0] - boxes[1]) < 12, 'first two tiles side by side');
  const overflow = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 0, `${overflow}px wider than the phone`);
  await phone.screenshot({ path: `${SHOTS}/t4-phone-wall.png`, fullPage: true });
  await phone.goto(listUrl);
  await row(phone, 'Decorate').waitFor();
  const overflow2 = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow2 <= 0, `list: ${overflow2}px wider than the phone`);
  await phone.screenshot({ path: `${SHOTS}/t5-phone-list.png`, fullPage: true });
  await phone.context().close();
});

await step('move a task (with its sub-task) to another list, and copy one back', async () => {
  await desk.goto(listUrl);
  await row(desk, 'Decorate').waitFor();
  await desk.evaluate(() => tPost('addTaskList', { name: 'Shopping' }));
  await act(desk, 'Buy gifts', 'move');
  await desk.locator('#m-list').waitFor();
  const options = await desk.locator('#m-list option').allTextContents();
  assert.equal(options[0], 'Shopping');
  assert.match(options[options.length - 1], /this list/);
  await desk.selectOption('#m-list', { label: 'Shopping' });
  await desk.click('form[data-form="move"] button[data-mode="move"]');
  await desk.locator('#status', { hasText: 'Moved to "Shopping"' }).waitFor();
  assert.equal(await desk.locator('.t-what', { hasText: 'Grandkids' }).count(), 0, 'its sub-task went with it');
  await desk.goto(`${BASE}/tasks.html`);
  await desk.click('.list-card:has-text("Shopping")');
  await row(desk, 'Grandkids').waitFor();
  await act(desk, 'Buy gifts', 'move');
  await desk.selectOption('#m-list', { label: 'Christmas prep' });
  await desk.click('form[data-form="move"] button[data-mode="copy"]');
  await desk.locator('#status', { hasText: 'Copied' }).waitFor();
  await row(desk, 'Buy gifts').waitFor();   // the copy leaves this one here
  await desk.goto(listUrl);
  await row(desk, 'Grandkids').waitFor();
});

await step('duplicate, then delete the copy', async () => {
  await desk.goto(listUrl);
  desk.nextPrompt = 'Christmas prep 2027';
  await desk.click('#dup-btn');
  await desk.getByRole('heading', { name: 'Christmas prep 2027' }).waitFor();
  await row(desk, 'Lights').waitFor();
  assert.equal(await desk.locator('[data-check]:checked').count(), 0);
  await desk.click('#del-list-btn');
  await desk.locator('.list-card').first().waitFor();
  assert.equal(await desk.locator('.list-card', { hasText: '2027' }).count(), 0);
});

await browser.close();
console.log(results.join('\n'));
console.log(consoleErrors.length ? `\nConsole errors:\n${consoleErrors.join('\n')}` : '\nNo console errors.');
process.exit(results.some(r => r.startsWith('FAIL')) || consoleErrors.length ? 1 : 0);
