<?php
// Life Tempo -- Task lists and the Wall (added 2026-11-25).
//
// Life Tempo's own task lists (separate from Master Checklist's, but shaped
// like them): tasks nest to grandchildren (depth 0-2), move up/down among
// their siblings, and "Clear checked" hides finished tasks until Restore.
// A list can be shared with other Life Tempo users by email: everyone on it
// can add, edit, check and arrange tasks; renaming, sharing and deleting the
// list stay with its owner. Every change bumps lt_task_lists.version so an
// open page can poll taskListVersion and pick up someone else's edit.
//
// Included by api.php, which calls handleTaskAction() before its own router.
// Guessed or unshared ids always answer "not found".
//
// Added 2026-11-26: a contact (name + phone, for tap-to-call/text), "Add to
// Plan" (a planned event on the caller's own Plan), and move/copy a task --
// with everything under it -- to another list the caller can reach.

const TASK_ACTIONS = [
  'taskLists', 'taskList', 'taskListVersion',
  'addTaskList', 'renameTaskList', 'deleteTaskList', 'duplicateTaskList',
  'shareTaskList', 'unshareTaskList',
  'addTask', 'updateTask', 'setTaskComplete', 'deleteTask',
  'indentTask', 'outdentTask', 'moveTask', 'clearCheckedTasks', 'restoreClearedTasks',
  'moveTaskToList', 'addTaskToPlan',
];
const TASK_MAX_DEPTH = 2;

// ---- Small query helpers ----

function tkRows(string $sql, string $types = '', array $values = []): array {
  $stmt = db()->prepare($sql);
  if ($types !== '') { $stmt->bind_param($types, ...$values); }
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}

function tkRow(string $sql, string $types = '', array $values = []): ?array {
  $rows = tkRows($sql, $types, $values);
  return $rows ? $rows[0] : null;
}

// Runs a write; returns the insert id, or the affected-row count when there's none.
function tkExec(string $sql, string $types = '', array $values = []): int {
  $stmt = db()->prepare($sql);
  if ($types !== '') { $stmt->bind_param($types, ...$values); }
  $stmt->execute();
  $n = $stmt->insert_id ? (int)$stmt->insert_id : (int)$stmt->affected_rows;
  $stmt->close();
  return $n;
}

function tkText($v, int $max): ?string {
  $s = trim((string)($v ?? ''));
  if ($s === '') { return null; }
  return mb_substr($s, 0, $max);
}

function tkName(array $u): string {
  return trim((string)($u['display_name'] ?? '')) ?: (string)$u['username'];
}

// ---- Access ----

/** The list, if the user owns it or it's shared with them; "not found" otherwise. */
function tkListFor(int $userId, int $listId): array {
  $row = tkRow(
    'SELECT l.* FROM lt_task_lists l
      WHERE l.id = ? AND (l.user_id = ? OR EXISTS (SELECT 1 FROM lt_task_list_shares s WHERE s.list_id = l.id AND s.user_id = ?))',
    'iii', [$listId, $userId, $userId]
  );
  if (!$row) { fail('List not found', 404); }
  return $row;
}

function tkOwnedList(int $userId, int $listId): array {
  $list = tkListFor($userId, $listId);
  if ((int)$list['user_id'] !== $userId) { fail('Only the person who made this list can do that', 403); }
  return $list;
}

/** The task, if its list is one the user can reach. */
function tkTaskFor(int $userId, int $taskId): array {
  $task = tkRow('SELECT * FROM lt_tasks WHERE id = ?', 'i', [$taskId]);
  if (!$task) { fail('Task not found', 404); }
  tkListFor($userId, (int)$task['list_id']);
  return $task;
}

function tkBump(int $listId): void {
  tkExec('UPDATE lt_task_lists SET version = version + 1 WHERE id = ?', 'i', [$listId]);
}

// ---- Tree helpers ----

/** id => row, for every task on the list (cleared ones included). */
function tkTree(int $listId): array {
  $byId = [];
  foreach (tkRows('SELECT id, parent_id, depth, sort_order, cleared_at FROM lt_tasks WHERE list_id = ? ORDER BY sort_order, id', 'i', [$listId]) as $r) {
    $byId[(int)$r['id']] = $r;
  }
  return $byId;
}

/** parent id (0 = top) => child ids in order. */
function tkChildren(array $byId): array {
  $map = [];
  foreach ($byId as $id => $r) { $map[$r['parent_id'] !== null ? (int)$r['parent_id'] : 0][] = $id; }
  return $map;
}

function tkDescendants(array $children, int $id): array {
  $out = [];
  foreach ($children[$id] ?? [] as $c) { $out[] = $c; $out = array_merge($out, tkDescendants($children, $c)); }
  return $out;
}

function tkSubtreeHeight(array $children, int $id): int {
  $h = 0;
  foreach ($children[$id] ?? [] as $c) { $h = max($h, 1 + tkSubtreeHeight($children, $c)); }
  return $h;
}

function tkRenumber(array $ids): void {
  foreach (array_values($ids) as $i => $id) {
    tkExec('UPDATE lt_tasks SET sort_order = ? WHERE id = ?', 'ii', [($i + 1) * 10, $id]);
  }
}

function tkNextSort(int $listId, ?int $parentId): int {
  $row = $parentId === null
    ? tkRow('SELECT COALESCE(MAX(sort_order), 0) AS m FROM lt_tasks WHERE list_id = ? AND parent_id IS NULL', 'i', [$listId])
    : tkRow('SELECT COALESCE(MAX(sort_order), 0) AS m FROM lt_tasks WHERE list_id = ? AND parent_id = ?', 'ii', [$listId, $parentId]);
  return (int)$row['m'] + 10;
}

function tkShapeTask(array $r): array {
  return [
    'Id' => (int)$r['id'], 'ParentId' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
    'Depth' => (int)$r['depth'], 'SortOrder' => (int)$r['sort_order'],
    'What' => (string)$r['what'], 'Where' => (string)($r['where_text'] ?? ''),
    'WhenDate' => $r['when_date'], 'WhenTime' => $r['when_time'] !== null ? substr((string)$r['when_time'], 0, 5) : null,
    'Who' => (string)($r['who'] ?? ''), 'Notes' => (string)($r['notes'] ?? ''),
    'ContactName' => (string)($r['contact_name'] ?? ''), 'ContactPhone' => (string)($r['contact_phone'] ?? ''),
    'Complete' => (bool)$r['complete'], 'CompletedBy' => $r['completed_name'] ?? null, 'CompletedAt' => $r['completed_at'],
    'Cleared' => $r['cleared_at'] !== null,
  ];
}

/** The editable fields of a task from a request body. Only keys present are returned. */
function tkTaskFields(array $b, bool $requireWhat): array {
  $out = [];
  if ($requireWhat || array_key_exists('what', $b)) {
    $what = tkText($b['what'] ?? '', 500);
    if ($what === null) { fail('Say what the task is'); }
    $out['what'] = $what;
  }
  if (array_key_exists('where', $b)) { $out['where_text'] = tkText($b['where'], 255); }
  if (array_key_exists('who', $b)) { $out['who'] = tkText($b['who'], 255); }
  if (array_key_exists('notes', $b)) { $out['notes'] = tkText($b['notes'], 5000); }
  if (array_key_exists('contactName', $b)) { $out['contact_name'] = tkText($b['contactName'], 255); }
  if (array_key_exists('contactPhone', $b)) {
    $ph = tkText($b['contactPhone'], 40);
    if ($ph !== null && (!preg_match('/^[0-9+().\-\s#*xXet,;]+$/', $ph) || strlen(preg_replace('/\D/', '', $ph)) < 3)) {
      fail('That phone number doesn\'t look right');
    }
    $out['contact_phone'] = $ph;
  }
  if (array_key_exists('whenDate', $b)) {
    $d = tkText($b['whenDate'], 10);
    if ($d !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { fail('That date doesn\'t look right'); }
    $out['when_date'] = $d;
  }
  if (array_key_exists('whenTime', $b)) {
    $t = tkText($b['whenTime'], 8);
    if ($t !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) { fail('That time doesn\'t look right'); }
    $out['when_time'] = $t;
  }
  return $out;
}

/** Inserts a copy of task row $t (unchecked) on $listId; returns the new id. */
function tkInsertCopy(array $t, int $listId, int $uid, ?int $parent, int $depth, int $sort): int {
  return tkExec(
    'INSERT INTO lt_tasks (list_id, created_by, parent_id, depth, sort_order, what, where_text, when_date, when_time, who, notes, contact_name, contact_phone)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'iiiiissssssss',
    [$listId, $uid, $parent, $depth, $sort, $t['what'], $t['where_text'], $t['when_date'], $t['when_time'], $t['who'], $t['notes'],
     $t['contact_name'] ?? null, $t['contact_phone'] ?? null]);
}

// ---- Sharing ----

function tkMembers(array $list): array {
  $owner = tkRow('SELECT id, username, display_name FROM users WHERE id = ?', 'i', [(int)$list['user_id']]);
  $out = [['UserId' => (int)$owner['id'], 'Name' => tkName($owner), 'Email' => (string)$owner['username'], 'Owner' => true]];
  foreach (tkRows('SELECT u.id, u.username, u.display_name FROM lt_task_list_shares s JOIN users u ON u.id = s.user_id
                    WHERE s.list_id = ? ORDER BY u.display_name, u.username', 'i', [(int)$list['id']]) as $u) {
    $out[] = ['UserId' => (int)$u['id'], 'Name' => tkName($u), 'Email' => (string)$u['username'], 'Owner' => false];
  }
  return $out;
}

// ---- Router ----

function handleTaskAction(string $action, array $body): void {
  $user = requireMember();
  $uid = (int)$user['id'];

  switch ($action) {

    case 'taskLists': {
      $rows = tkRows(
        'SELECT l.id, l.name, l.user_id, l.version, u.username, u.display_name,
                (SELECT COUNT(*) FROM lt_tasks t WHERE t.list_id = l.id AND t.cleared_at IS NULL AND t.complete = 0) AS open_n,
                (SELECT COUNT(*) FROM lt_tasks t WHERE t.list_id = l.id AND t.cleared_at IS NULL AND t.complete = 1) AS done_n,
                (SELECT COUNT(*) FROM lt_task_list_shares s WHERE s.list_id = l.id) AS share_n
           FROM lt_task_lists l JOIN users u ON u.id = l.user_id
          WHERE l.user_id = ? OR EXISTS (SELECT 1 FROM lt_task_list_shares s WHERE s.list_id = l.id AND s.user_id = ?)
          ORDER BY (l.user_id = ?) DESC, l.name',
        'iii', [$uid, $uid, $uid]
      );
      respond(['ok' => true, 'lists' => array_map(fn($r) => [
        'Id' => (int)$r['id'], 'Name' => (string)$r['name'], 'Mine' => (int)$r['user_id'] === $uid,
        'OwnerName' => tkName($r), 'Open' => (int)$r['open_n'], 'Done' => (int)$r['done_n'],
        'SharedCount' => (int)$r['share_n'], 'Version' => (int)$r['version'],
      ], $rows)]);
    }

    case 'taskList': {
      $list = tkListFor($uid, (int)($_GET['id'] ?? 0));
      $tasks = tkRows(
        'SELECT t.*, COALESCE(NULLIF(TRIM(u.display_name), \'\'), u.username) AS completed_name
           FROM lt_tasks t LEFT JOIN users u ON u.id = t.completed_by
          WHERE t.list_id = ? ORDER BY t.depth, t.sort_order, t.id', 'i', [(int)$list['id']]);
      respond(['ok' => true, 'list' => [
        'Id' => (int)$list['id'], 'Name' => (string)$list['name'], 'Mine' => (int)$list['user_id'] === $uid,
        'Version' => (int)$list['version'], 'Members' => tkMembers($list),
      ], 'tasks' => array_map('tkShapeTask', $tasks)]);
    }

    // Cheap check for the page's 10-second refresh.
    case 'taskListVersion': {
      $list = tkListFor($uid, (int)($_GET['id'] ?? 0));
      respond(['ok' => true, 'version' => (int)$list['version']]);
    }

    case 'addTaskList': {
      $name = tkText($body['name'] ?? '', 255);
      if ($name === null) { fail('Name the list'); }
      $id = tkExec('INSERT INTO lt_task_lists (user_id, name) VALUES (?, ?)', 'is', [$uid, $name]);
      respond(['ok' => true, 'id' => $id]);
    }

    case 'renameTaskList': {
      $list = tkOwnedList($uid, (int)($body['id'] ?? 0));
      $name = tkText($body['name'] ?? '', 255);
      if ($name === null) { fail('Name the list'); }
      tkExec('UPDATE lt_task_lists SET name = ?, version = version + 1 WHERE id = ?', 'si', [$name, (int)$list['id']]);
      respond(['ok' => true]);
    }

    case 'deleteTaskList': {
      $list = tkOwnedList($uid, (int)($body['id'] ?? 0));
      tkExec('DELETE FROM lt_task_lists WHERE id = ?', 'i', [(int)$list['id']]);
      respond(['ok' => true]);
    }

    // A new list owned by the caller, with the visible tasks copied unchecked.
    case 'duplicateTaskList': {
      $list = tkListFor($uid, (int)($body['id'] ?? 0));
      $name = tkText($body['name'] ?? '', 255) ?? ($list['name'] . ' (copy)');
      $tasks = tkRows('SELECT * FROM lt_tasks WHERE list_id = ? AND cleared_at IS NULL ORDER BY depth, sort_order, id', 'i', [(int)$list['id']]);
      db()->begin_transaction();
      $newId = tkExec('INSERT INTO lt_task_lists (user_id, name) VALUES (?, ?)', 'is', [$uid, $name]);
      $map = [];
      $depths = [];
      foreach ($tasks as $t) {
        $old = $t['parent_id'] !== null ? (int)$t['parent_id'] : null;
        $parent = $old !== null && isset($map[$old]) ? $map[$old] : null;
        if ($old !== null && $parent === null) { continue; }   // under a cleared task: left out with it
        $depth = $parent === null ? 0 : $depths[$parent] + 1;
        $nid = tkInsertCopy($t, $newId, $uid, $parent, $depth, (int)$t['sort_order']);
        $map[(int)$t['id']] = $nid;
        $depths[$nid] = $depth;
      }
      db()->commit();
      respond(['ok' => true, 'id' => $newId]);
    }

    case 'shareTaskList': {
      $list = tkOwnedList($uid, (int)($body['id'] ?? 0));
      $email = trim((string)($body['email'] ?? ''));
      if ($email === '') { fail('Enter an email address'); }
      $target = tkRow('SELECT id, username, display_name FROM users WHERE username = ?', 's', [$email]);
      if (!$target || !hasAppAccess($target)) { fail('No Life Tempo user has that email', 404); }
      if ((int)$target['id'] === $uid) { fail('That\'s you — the list is already yours'); }
      tkExec('INSERT IGNORE INTO lt_task_list_shares (list_id, user_id) VALUES (?, ?)', 'ii', [(int)$list['id'], (int)$target['id']]);
      tkBump((int)$list['id']);
      respond(['ok' => true, 'members' => tkMembers($list)]);
    }

    // The owner removes someone, or someone leaves a list shared with them.
    case 'unshareTaskList': {
      $list = tkListFor($uid, (int)($body['id'] ?? 0));
      $who = (int)($body['userId'] ?? 0);
      if ($who !== $uid && (int)$list['user_id'] !== $uid) { fail('Only the person who made this list can do that', 403); }
      tkExec('DELETE FROM lt_task_list_shares WHERE list_id = ? AND user_id = ?', 'ii', [(int)$list['id'], $who]);
      tkBump((int)$list['id']);
      respond(['ok' => true, 'members' => tkMembers($list)]);
    }

    case 'addTask': {
      $list = tkListFor($uid, (int)($body['listId'] ?? 0));
      $listId = (int)$list['id'];
      $parentId = (int)($body['parentId'] ?? 0) ?: null;
      $depth = 0;
      if ($parentId !== null) {
        $parent = tkRow('SELECT id, depth FROM lt_tasks WHERE id = ? AND list_id = ?', 'ii', [$parentId, $listId]);
        if (!$parent) { fail('Task not found', 404); }
        $depth = (int)$parent['depth'] + 1;
        if ($depth > TASK_MAX_DEPTH) { fail('Grandchild is the deepest level — add it beside this one instead'); }
      }
      $f = tkTaskFields($body, true);
      $id = tkExec(
        'INSERT INTO lt_tasks (list_id, created_by, parent_id, depth, sort_order, what, where_text, when_date, when_time, who, notes, contact_name, contact_phone)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'iiiiissssssss',
        [$listId, $uid, $parentId, $depth, tkNextSort($listId, $parentId), $f['what'], $f['where_text'] ?? null,
         $f['when_date'] ?? null, $f['when_time'] ?? null, $f['who'] ?? null, $f['notes'] ?? null,
         $f['contact_name'] ?? null, $f['contact_phone'] ?? null]);
      tkBump($listId);
      respond(['ok' => true, 'id' => $id]);
    }

    case 'updateTask': {
      $task = tkTaskFor($uid, (int)($body['id'] ?? 0));
      $f = tkTaskFields($body, false);
      if (!$f) { fail('Nothing to change'); }
      $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($f)));
      tkExec("UPDATE lt_tasks SET $sets WHERE id = ?", str_repeat('s', count($f)) . 'i', [...array_values($f), (int)$task['id']]);
      tkBump((int)$task['list_id']);
      respond(['ok' => true]);
    }

    // Checking a task never changes its sub-tasks -- each is checked on its own.
    case 'setTaskComplete': {
      $task = tkTaskFor($uid, (int)($body['id'] ?? 0));
      if (!empty($body['complete'])) {
        tkExec('UPDATE lt_tasks SET complete = 1, completed_by = ?, completed_at = NOW() WHERE id = ?', 'ii', [$uid, (int)$task['id']]);
      } else {
        tkExec('UPDATE lt_tasks SET complete = 0, completed_by = NULL, completed_at = NULL WHERE id = ?', 'i', [(int)$task['id']]);
      }
      tkBump((int)$task['list_id']);
      respond(['ok' => true]);
    }

    // Its children and grandchildren go with it (foreign key cascade).
    case 'deleteTask': {
      $task = tkTaskFor($uid, (int)($body['id'] ?? 0));
      tkExec('DELETE FROM lt_tasks WHERE id = ?', 'i', [(int)$task['id']]);
      tkBump((int)$task['list_id']);
      respond(['ok' => true]);
    }

    // Nest under the nearest visible sibling above, as its last child.
    case 'indentTask': {
      $task = tkTaskFor($uid, (int)($body['id'] ?? 0));
      $id = (int)$task['id'];
      $listId = (int)$task['list_id'];
      $byId = tkTree($listId);
      $children = tkChildren($byId);
      $newParent = null;
      foreach ($children[$task['parent_id'] !== null ? (int)$task['parent_id'] : 0] ?? [] as $sib) {
        if ($sib === $id) { break; }
        if ($byId[$sib]['cleared_at'] === null) { $newParent = $sib; }
      }
      if ($newParent === null) { fail('There\'s no task above this one to put it under'); }
      $newDepth = (int)$task['depth'] + 1;
      if ($newDepth + tkSubtreeHeight($children, $id) > TASK_MAX_DEPTH) { fail('Can\'t go deeper — grandchild is the deepest level'); }
      tkExec('UPDATE lt_tasks SET parent_id = ?, depth = ?, sort_order = ? WHERE id = ?', 'iiii', [$newParent, $newDepth, tkNextSort($listId, $newParent), $id]);
      $below = tkDescendants($children, $id);
      foreach ($below as $d) { tkExec('UPDATE lt_tasks SET depth = depth + 1 WHERE id = ?', 'i', [$d]); }
      tkBump($listId);
      respond(['ok' => true]);
    }

    // Becomes the next sibling of its old parent.
    case 'outdentTask': {
      $task = tkTaskFor($uid, (int)($body['id'] ?? 0));
      if ($task['parent_id'] === null) { fail('This task is already at the top level'); }
      $id = (int)$task['id'];
      $listId = (int)$task['list_id'];
      $byId = tkTree($listId);
      $children = tkChildren($byId);
      $oldParent = (int)$task['parent_id'];
      $newParent = $byId[$oldParent]['parent_id'] !== null ? (int)$byId[$oldParent]['parent_id'] : null;
      tkExec('UPDATE lt_tasks SET parent_id = ?, depth = depth - 1 WHERE id = ?', 'ii', [$newParent, $id]);
      foreach (tkDescendants($children, $id) as $d) { tkExec('UPDATE lt_tasks SET depth = depth - 1 WHERE id = ?', 'i', [$d]); }
      $order = [];
      foreach ($children[$newParent ?? 0] ?? [] as $sib) {
        $order[] = $sib;
        if ($sib === $oldParent) { $order[] = $id; }
      }
      tkRenumber($order);
      tkBump($listId);
      respond(['ok' => true]);
    }

    // Swap with the visible sibling above or below; sub-tasks come along.
    // This is the only way tiles move on the Wall.
    case 'moveTask': {
      $task = tkTaskFor($uid, (int)($body['id'] ?? 0));
      $dir = (string)($body['direction'] ?? '');
      if ($dir !== 'up' && $dir !== 'down') { fail('Move up or down'); }
      $id = (int)$task['id'];
      $byId = tkTree((int)$task['list_id']);
      $sibs = tkChildren($byId)[$task['parent_id'] !== null ? (int)$task['parent_id'] : 0] ?? [];
      $visible = array_values(array_filter($sibs, fn($s) => $byId[$s]['cleared_at'] === null));
      $vi = array_search($id, $visible, true);
      $ni = $dir === 'up' ? $vi - 1 : $vi + 1;
      if ($vi === false || $ni < 0 || $ni >= count($visible)) { fail($dir === 'up' ? 'Already at the top' : 'Already at the bottom'); }
      $a = array_search($id, $sibs, true);
      $b = array_search($visible[$ni], $sibs, true);
      [$sibs[$a], $sibs[$b]] = [$sibs[$b], $sibs[$a]];
      tkRenumber($sibs);
      tkBump((int)$task['list_id']);
      respond(['ok' => true]);
    }

    // Hides checked tasks (and everything under them) until Restore.
    case 'clearCheckedTasks': {
      $list = tkListFor($uid, (int)($body['listId'] ?? 0));
      $n = tkExec('UPDATE lt_tasks SET cleared_at = NOW() WHERE list_id = ? AND complete = 1 AND cleared_at IS NULL', 'i', [(int)$list['id']]);
      tkBump((int)$list['id']);
      respond(['ok' => true, 'cleared' => $n]);
    }

    case 'restoreClearedTasks': {
      $list = tkListFor($uid, (int)($body['listId'] ?? 0));
      $n = tkExec('UPDATE lt_tasks SET cleared_at = NULL WHERE list_id = ? AND cleared_at IS NOT NULL', 'i', [(int)$list['id']]);
      tkBump((int)$list['id']);
      respond(['ok' => true, 'restored' => $n]);
    }

    // Sends a task and everything under it to the bottom of another list the
    // caller can reach, as a top-level task there. Move keeps checks (and
    // takes cleared sub-tasks along); copy makes fresh, unchecked tasks and
    // leaves cleared ones behind.
    case 'moveTaskToList': {
      $task = tkTaskFor($uid, (int)($body['id'] ?? 0));
      $from = (int)$task['list_id'];
      $to = (int)tkListFor($uid, (int)($body['toListId'] ?? 0))['id'];
      $copy = !empty($body['copy']);
      if ($to === $from && !$copy) { fail('It\'s already on this list'); }
      $id = (int)$task['id'];
      $byId = tkTree($from);
      $children = tkChildren($byId);
      $shift = (int)$task['depth'];
      $sort = tkNextSort($to, null);
      db()->begin_transaction();
      if ($copy) {
        $map = [$id => tkInsertCopy($task, $to, $uid, null, 0, $sort)];
        foreach (tkDescendants($children, $id) as $d) {   // parents always come before their children
          if ($byId[$d]['cleared_at'] !== null || !isset($map[(int)$byId[$d]['parent_id']])) { continue; }
          $row = tkRow('SELECT * FROM lt_tasks WHERE id = ?', 'i', [$d]);
          $map[$d] = tkInsertCopy($row, $to, $uid, $map[(int)$row['parent_id']], (int)$row['depth'] - $shift, (int)$row['sort_order']);
        }
        $newId = $map[$id];
      } else {
        tkExec('UPDATE lt_tasks SET list_id = ?, parent_id = NULL, depth = 0, sort_order = ? WHERE id = ?', 'iii', [$to, $sort, $id]);
        foreach (tkDescendants($children, $id) as $d) {
          tkExec('UPDATE lt_tasks SET list_id = ?, depth = depth - ? WHERE id = ?', 'iii', [$to, $shift, $d]);
        }
        tkBump($from);
        $newId = $id;
      }
      tkBump($to);
      db()->commit();
      respond(['ok' => true, 'id' => $newId]);
    }

    // Puts the task on the caller's own Plan (lt_planned_events). Plan needs
    // a date and a start time; the page fills them in from the task. "Where"
    // becomes the event's place -- an existing saved location with that name,
    // or a new one, the same as typing a place on the Plan page.
    case 'addTaskToPlan': {
      $task = tkTaskFor($uid, (int)($body['id'] ?? 0));
      $date = trim((string)($body['date'] ?? ''));
      $time = trim((string)($body['time'] ?? ''));
      if ($date === '' || $time === '') { fail('Plan needs a date and a start time'); }
      $title = mb_substr((string)$task['what'], 0, 200);
      $start = requireDateTime($date, $time, 'start');
      $dupe = tkRow("SELECT id FROM lt_planned_events WHERE user_id = ? AND title = ? AND start_datetime = ? AND status = 'Planned'",
        'iss', [$uid, $title, $start]);
      if ($dupe) { fail('That\'s already on your Plan at that time', 409); }
      $notes = [];
      if ($task['contact_name'] || $task['contact_phone']) {
        $notes[] = 'Contact: ' . trim(($task['contact_name'] ?? '') . ' ' . ($task['contact_phone'] ?? ''));
      }
      if ($task['who']) { $notes[] = 'Who: ' . $task['who']; }
      if ($task['notes']) { $notes[] = $task['notes']; }
      $list = tkRow('SELECT name FROM lt_task_lists WHERE id = ?', 'i', [(int)$task['list_id']]);
      $notes[] = 'From task list: ' . $list['name'];
      $event = ['title' => $title, 'startDate' => $date, 'startTime' => $time,
        'endTime' => trim((string)($body['endTime'] ?? '')), 'notes' => mb_substr(implode("\n", $notes), 0, 1000)];
      $where = trim((string)($task['where_text'] ?? ''));
      if ($where !== '') {
        $loc = tkRow('SELECT id FROM lt_locations WHERE user_id = ? AND LOWER(name) = LOWER(?) ORDER BY id LIMIT 1', 'is', [$uid, $where]);
        if ($loc) { $event['locationId'] = (int)$loc['id']; } else { $event['newLocation'] = ['name' => $where, 'address' => '']; }
      }
      $eventId = addPlannedEvent($uid, $event);
      respond(['ok' => true, 'eventId' => $eventId]);
    }
  }
}
