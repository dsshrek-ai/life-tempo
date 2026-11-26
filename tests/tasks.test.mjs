// HTTP tests for task lists and the Wall (api/tasks.php).
// Needs a throwaway database with My Apps Hub's schema and api/schema.sql, users
// dennis@test and karen@test with Life Tempo access, nolt@test without it, and
// sessions tok-dennis, tok-karen, tok-nolt. Serve a copy of the repo with a test config:
//   API_URL=http://127.0.0.1:8200/api/api.php node tests/tasks.test.mjs
import assert from 'node:assert/strict';

const API = process.env.API_URL;
if (!API) { console.error('Set API_URL'); process.exit(2); }

async function call(who, action, body = null, params = {}) {
  const url = new URL(API);
  if (!body) { url.searchParams.set('action', action); }
  for (const [k, v] of Object.entries(params)) { url.searchParams.set(k, String(v)); }
  const res = await fetch(url, {
    method: body ? 'POST' : 'GET',
    headers: { Authorization: `Bearer tok-${who}`, 'Content-Type': 'text/plain' },
    body: body ? JSON.stringify({ action, ...body }) : undefined,
  });
  return { status: res.status, data: await res.json() };
}
const get = (who, action, params) => call(who, action, null, params);
const tasksOf = async (who, id) => (await get(who, 'taskList', { id })).data.tasks;
const byWhat = (tasks, what) => tasks.find(t => t.What === what);

const results = [];
async function test(name, fn) {
  try { await fn(); results.push(['PASS', name]); } catch (e) { results.push(['FAIL', name, e.message]); }
}

let list = 0;
const ids = {};

await test('a user without Life Tempo access is refused', async () => {
  assert.equal((await get('nolt', 'taskLists')).status, 403);
});

await test('create a list and add tasks, children and a grandchild', async () => {
  const r = await call('dennis', 'addTaskList', { name: 'Garage clean-out' });
  assert.equal(r.status, 200);
  list = r.data.id;
  for (const what of ['Sort tools', 'Haul trash', 'Sweep floor']) {
    ids[what] = (await call('dennis', 'addTask', { listId: list, what })).data.id;
  }
  ids.Wrenches = (await call('dennis', 'addTask', { listId: list, parentId: ids['Sort tools'], what: 'Wrenches', who: 'Karen', whenDate: '2026-12-01', whenTime: '09:30' })).data.id;
  ids.Metric = (await call('dennis', 'addTask', { listId: list, parentId: ids.Wrenches, what: 'Metric set' })).data.id;
  const deeper = await call('dennis', 'addTask', { listId: list, parentId: ids.Metric, what: 'Too deep' });
  assert.equal(deeper.status, 400);
  const tasks = await tasksOf('dennis', list);
  assert.equal(tasks.length, 5);
  const w = byWhat(tasks, 'Wrenches');
  assert.deepEqual([w.Depth, w.ParentId, w.Who, w.WhenDate, w.WhenTime], [1, ids['Sort tools'], 'Karen', '2026-12-01', '09:30']);
  assert.equal(byWhat(tasks, 'Metric set').Depth, 2);
});

await test('empty "what" and a bad date are refused', async () => {
  assert.equal((await call('dennis', 'addTask', { listId: list, what: '   ' })).status, 400);
  assert.equal((await call('dennis', 'updateTask', { id: ids['Haul trash'], whenDate: 'next week' })).status, 400);
});

await test('someone the list isn\'t shared with gets "not found" for everything', async () => {
  assert.equal((await get('karen', 'taskList', { id: list })).status, 404);
  assert.equal((await call('karen', 'addTask', { listId: list, what: 'x' })).status, 404);
  assert.equal((await call('karen', 'setTaskComplete', { id: ids['Haul trash'], complete: true })).status, 404);
  assert.equal((await get('karen', 'taskLists')).data.lists.length, 0);
});

await test('share by email: unknown and no-access emails are refused; Karen gets the list', async () => {
  assert.equal((await call('dennis', 'shareTaskList', { id: list, email: 'nobody@test' })).status, 404);
  assert.equal((await call('dennis', 'shareTaskList', { id: list, email: 'nolt@test' })).status, 404);
  const r = await call('dennis', 'shareTaskList', { id: list, email: 'karen@test' });
  assert.equal(r.status, 200);
  assert.deepEqual(r.data.members.map(m => [m.Name, m.Owner]), [['Dennis', true], ['Karen', false]]);
  const lists = (await get('karen', 'taskLists')).data.lists;
  assert.deepEqual(lists.map(l => [l.Name, l.Mine, l.OwnerName]), [['Garage clean-out', false, 'Dennis']]);
});

await test('a shared member can add, edit and check tasks — and the version moves so the other page refreshes', async () => {
  const v0 = (await get('dennis', 'taskListVersion', { id: list })).data.version;
  assert.equal((await call('karen', 'addTask', { listId: list, what: 'Donate old bike' })).status, 200);
  assert.equal((await call('karen', 'updateTask', { id: ids['Haul trash'], where: 'Dump on 5th', notes: 'Bring gloves' })).status, 200);
  assert.equal((await call('karen', 'setTaskComplete', { id: ids['Sweep floor'], complete: true })).status, 200);
  const v1 = (await get('dennis', 'taskListVersion', { id: list })).data.version;
  assert.equal(v1, v0 + 3);
  const t = await tasksOf('dennis', list);
  assert.equal(byWhat(t, 'Haul trash').Where, 'Dump on 5th');
  assert.equal(byWhat(t, 'Sweep floor').Complete, true);
  assert.equal(byWhat(t, 'Sweep floor').CompletedBy, 'Karen');
});

await test('checking a parent leaves its sub-tasks alone; unchecking clears who did it', async () => {
  await call('dennis', 'setTaskComplete', { id: ids['Sort tools'], complete: true });
  let t = await tasksOf('dennis', list);
  assert.equal(byWhat(t, 'Sort tools').Complete, true);
  assert.equal(byWhat(t, 'Wrenches').Complete, false);
  await call('dennis', 'setTaskComplete', { id: ids['Sort tools'], complete: false });
  t = await tasksOf('dennis', list);
  assert.equal(byWhat(t, 'Sort tools').CompletedBy, null);
});

await test('only the owner can rename, share or delete; a member can leave', async () => {
  assert.equal((await call('karen', 'renameTaskList', { id: list, name: 'Mine now' })).status, 403);
  assert.equal((await call('karen', 'deleteTaskList', { id: list })).status, 403);
  assert.equal((await call('karen', 'shareTaskList', { id: list, email: 'dennis@test' })).status, 403);
  assert.equal((await call('karen', 'unshareTaskList', { id: list, userId: 1 })).status, 403);
  assert.equal((await call('dennis', 'renameTaskList', { id: list, name: 'Garage' })).status, 200);
});

await test('move up/down swaps with the neighbour and brings sub-tasks along (this is how tiles move)', async () => {
  const top = async () => (await tasksOf('dennis', list)).filter(t => t.Depth === 0).sort((a, b) => a.SortOrder - b.SortOrder).map(t => t.What);
  assert.deepEqual(await top(), ['Sort tools', 'Haul trash', 'Sweep floor', 'Donate old bike']);
  await call('dennis', 'moveTask', { id: ids['Sort tools'], direction: 'down' });
  assert.deepEqual(await top(), ['Haul trash', 'Sort tools', 'Sweep floor', 'Donate old bike']);
  assert.equal(byWhat(await tasksOf('dennis', list), 'Wrenches').ParentId, ids['Sort tools']);
  assert.equal((await call('dennis', 'moveTask', { id: ids['Haul trash'], direction: 'up' })).status, 400);
});

await test('indent and outdent keep the grandchild limit', async () => {
  // "Sort tools" has a grandchild, so it can't go under "Haul trash".
  assert.equal((await call('dennis', 'indentTask', { id: ids['Sort tools'] })).status, 400);
  assert.equal((await call('dennis', 'indentTask', { id: ids['Haul trash'] })).status, 400, 'nothing above it');
  assert.equal((await call('dennis', 'indentTask', { id: ids['Sweep floor'] })).status, 200);
  let t = await tasksOf('dennis', list);
  assert.deepEqual([byWhat(t, 'Sweep floor').ParentId, byWhat(t, 'Sweep floor').Depth], [ids['Sort tools'], 1]);
  assert.equal((await call('dennis', 'outdentTask', { id: ids.Wrenches })).status, 200);
  t = await tasksOf('dennis', list);
  const top = t.filter(x => x.Depth === 0).sort((a, b) => a.SortOrder - b.SortOrder).map(x => x.What);
  assert.deepEqual(top, ['Haul trash', 'Sort tools', 'Wrenches', 'Donate old bike']);
  assert.equal(byWhat(t, 'Metric set').Depth, 1);
});

await test('clear checked hides finished tasks; restore brings them back', async () => {
  await call('dennis', 'setTaskComplete', { id: ids['Haul trash'], complete: true });
  const r = await call('karen', 'clearCheckedTasks', { listId: list });
  assert.equal(r.data.cleared, 2);
  let t = await tasksOf('dennis', list);
  assert.equal(byWhat(t, 'Haul trash').Cleared, true);
  const summary = (await get('dennis', 'taskLists')).data.lists[0];
  assert.deepEqual([summary.Open, summary.Done], [4, 0]);
  assert.equal((await call('dennis', 'restoreClearedTasks', { listId: list })).data.restored, 2);
  t = await tasksOf('dennis', list);
  assert.ok(t.every(x => !x.Cleared));
});

await test('duplicate: Karen gets her own copy, unchecked, with the same shape', async () => {
  const r = await call('karen', 'duplicateTaskList', { id: list, name: 'Karen\'s garage' });
  assert.equal(r.status, 200);
  const copy = await get('karen', 'taskList', { id: r.data.id });
  assert.equal(copy.data.list.Mine, true);
  assert.equal(copy.data.tasks.length, 6);
  assert.ok(copy.data.tasks.every(x => !x.Complete));
  assert.equal(byWhat(copy.data.tasks, 'Metric set').Depth, 1);
  assert.equal((await get('dennis', 'taskList', { id: r.data.id })).status, 404);
});

await test('delete a task takes its sub-tasks with it', async () => {
  await call('dennis', 'deleteTask', { id: ids['Sort tools'] });
  const t = await tasksOf('dennis', list);
  assert.equal(byWhat(t, 'Sweep floor'), undefined);
  assert.equal(t.length, 4);
});

await test('a member leaves; then the owner deletes the list', async () => {
  assert.equal((await call('karen', 'unshareTaskList', { id: list, userId: 2 })).status, 200);
  assert.equal((await get('karen', 'taskList', { id: list })).status, 404);
  assert.equal((await call('dennis', 'deleteTaskList', { id: list })).status, 200);
  assert.equal((await get('dennis', 'taskList', { id: list })).status, 404);
});

for (const [s, n, m] of results) { console.log(`${s}  ${n}${m ? ' — ' + m : ''}`); }
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
