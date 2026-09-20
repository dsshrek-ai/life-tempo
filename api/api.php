<?php
// Life Tempo API
//
// Phase 1 (Foundation and Security): every private action goes through
// requireMember(), which resolves the bearer token to a UserID against the
// shared MyDataWorld `sessions`/`users` tables and checks an `app_access`
// grant for APP_KEY. See docs/life-tempo-spec.md section 94 for the rule
// this enforces (never trust a client-supplied UserID; always resolve it
// server-side from the session).
//
// Phase 2 (Core Activity Logging) adds Categories, Locations, Activities,
// and the ActivityLog itself, all scoped by the same requireMember()-derived
// UserID, and any category/location reference is re-validated as owned by
// that user via ownedId() before it's written.
//
// Phase 3 (Goals, Cadence, and Weekly Progress) adds Goals (linked to the
// activities that count toward them via lt_goal_activity) and DayStatus
// (marks a date as Travel/Vacation/etc. so Daily-cadence goals don't expect
// activity on days that aren't "home days" -- spec section 13/62).
// computeGoalProgress() is the whole weekly-dashboard calculation: it always
// evaluates the current week for Daily/Weekly goals and the current month
// for Monthly goals, so there's no persisted "snapshot" table yet (spec
// section 79 -- add one later only if live calculation proves too slow).
//
// Phase 4 (People, Shared Life, and Learning) adds People and Tags (linked
// per log entry via lt_activity_log_person/lt_activity_log_tag through the
// same syncBridge() used for Goal<->Activity), a Shared Life flag directly
// on lt_activity_log, and Learning Projects (linked 1:1 via two nullable
// columns rather than a separate join table -- see schema.sql for why).
//
// Phase 5 (Engagement Scoring and Heat Maps) adds Goal.Weight and
// computeEngagement(), which scales every active goal's target to whatever
// period is asked about (week/rolling-4-weeks/month/quarter/year, or a
// heat map's per-week rows) and produces a transparent weighted score --
// nothing here is persisted (spec section 129), it's all calculated live.
//
// Phase 6 (Planning, Seasons, and Calendar-Friendly Behavior) adds Seasons
// (a goal linked to one only counts while today falls in it), PlannedEvent
// (completing one creates its ActivityLog entry automatically, tying the
// two together via planned_event_id), and setDayStatusRange() for marking
// a whole trip/vacation at once instead of day by day.

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

ini_set('display_errors', '0');
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['ok' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// ---- Auth: shared MyDataWorld login + an app_access grant for 'life-tempo' ----

const APP_KEY = 'life-tempo';

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $token = $m[1];
  $stmt = db()->prepare(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    fail('Session expired or invalid -- please log in again', 401);
  }
  return $row;
}

function hasAppAccess(array $user): bool {
  $stmt = db()->prepare(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id
     WHERE aa.user_id = ? AND a.app_key = ?'
  );
  $key = APP_KEY;
  $stmt->bind_param('is', $user['id'], $key);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_row();
  $stmt->close();
  return (bool)$ok;
}

function logAppUsage(int $userId): void {
  try {
    $key = APP_KEY;
    $stmt = db()->prepare(
      'INSERT INTO app_usage_log (user_id, app_key, access_date, first_seen_at, last_seen_at, hit_count)
       VALUES (?, ?, CURDATE(), NOW(), NOW(), 1)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hit_count = hit_count + 1'
    );
    $stmt->bind_param('is', $userId, $key);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    // best-effort
  }
}

// Every private/domain action should call this (never requireUser() alone) --
// it's the one place that checks the app_access grant, so a future action
// can't accidentally skip authorization by forgetting the check.
function requireMember(): array {
  $user = requireUser();
  if (!hasAppAccess($user)) {
    fail('Not authorized for Life Tempo', 403);
  }
  logAppUsage((int)$user['id']);
  return $user;
}

// ---- Phase 2: shared helpers ----

// Returns $id if it's a positive int owned by $userId in $table, else null --
// silently drops a bad/foreign reference instead of failing the whole
// request, since these are always optional dropdown selections.
function ownedId(string $table, ?int $id, int $userId): ?int {
  if ($id === null || $id <= 0) { return null; }
  $stmt = db()->prepare("SELECT id FROM `$table` WHERE id = ? AND user_id = ?");
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_row();
  $stmt->close();
  return $row ? (int)$row[0] : null;
}

function nullIfEmpty(string $s): ?string {
  $s = trim($s);
  return $s === '' ? null : $s;
}

function duplicateNameFail(mysqli_sql_exception $e, string $label): void {
  if ($e->getCode() === 1062) { fail("A $label with that name already exists"); }
  throw $e;
}

// ---- Phase 2: Categories ----

function listCategories(int $userId): array {
  $stmt = db()->prepare(
    'SELECT id, name, description, sort_order, active
     FROM lt_categories WHERE user_id = ? ORDER BY active DESC, sort_order, name'
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'Name' => (string)$r['name'],
      'Description' => (string)($r['description'] ?? ''),
      'SortOrder' => (int)$r['sort_order'],
      'Active' => (bool)$r['active'],
    ];
  }
  $stmt->close();
  return $out;
}

function addCategory(int $userId, array $b): int {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Category name is required'); }
  $description = nullIfEmpty((string)($b['description'] ?? ''));
  $sortOrder = (int)($b['sortOrder'] ?? 0);
  try {
    $stmt = db()->prepare(
      'INSERT INTO lt_categories (user_id, name, description, sort_order) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('issi', $userId, $name, $description, $sortOrder);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'category');
  }
}

function updateCategory(int $userId, int $id, array $b): void {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Category name is required'); }
  $description = nullIfEmpty((string)($b['description'] ?? ''));
  $sortOrder = (int)($b['sortOrder'] ?? 0);
  $active = !empty($b['active']) ? 1 : 0;
  try {
    $stmt = db()->prepare(
      'UPDATE lt_categories SET name = ?, description = ?, sort_order = ?, active = ?
       WHERE id = ? AND user_id = ?'
    );
    $stmt->bind_param('ssiiii', $name, $description, $sortOrder, $active, $id, $userId);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'category');
  }
}

// ---- Phase 2: Locations ----

function listLocations(int $userId): array {
  $stmt = db()->prepare(
    'SELECT id, name, location_type, address, city, state_region, postal_code,
            phone, website_url, notes, active
     FROM lt_locations WHERE user_id = ? ORDER BY active DESC, name'
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'Name' => (string)$r['name'],
      'LocationType' => (string)($r['location_type'] ?? ''),
      'Address' => (string)($r['address'] ?? ''),
      'City' => (string)($r['city'] ?? ''),
      'StateRegion' => (string)($r['state_region'] ?? ''),
      'PostalCode' => (string)($r['postal_code'] ?? ''),
      'Phone' => (string)($r['phone'] ?? ''),
      'WebsiteUrl' => (string)($r['website_url'] ?? ''),
      'Notes' => (string)($r['notes'] ?? ''),
      'Active' => (bool)$r['active'],
    ];
  }
  $stmt->close();
  return $out;
}

function locationFields(array $b): array {
  return [
    trim((string)($b['name'] ?? '')),
    nullIfEmpty((string)($b['locationType'] ?? '')),
    nullIfEmpty((string)($b['address'] ?? '')),
    nullIfEmpty((string)($b['city'] ?? '')),
    nullIfEmpty((string)($b['stateRegion'] ?? '')),
    nullIfEmpty((string)($b['postalCode'] ?? '')),
    nullIfEmpty((string)($b['phone'] ?? '')),
    nullIfEmpty((string)($b['websiteUrl'] ?? '')),
    nullIfEmpty((string)($b['notes'] ?? '')),
  ];
}

function addLocation(int $userId, array $b): int {
  [$name, $locationType, $address, $city, $stateRegion, $postalCode, $phone, $websiteUrl, $notes] = locationFields($b);
  if ($name === '') { fail('Location name is required'); }
  $stmt = db()->prepare(
    'INSERT INTO lt_locations
      (user_id, name, location_type, address, city, state_region, postal_code, phone, website_url, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->bind_param('isssssssss', $userId, $name, $locationType, $address, $city, $stateRegion, $postalCode, $phone, $websiteUrl, $notes);
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return $id;
}

function updateLocation(int $userId, int $id, array $b): void {
  [$name, $locationType, $address, $city, $stateRegion, $postalCode, $phone, $websiteUrl, $notes] = locationFields($b);
  if ($name === '') { fail('Location name is required'); }
  $active = !empty($b['active']) ? 1 : 0;
  $stmt = db()->prepare(
    'UPDATE lt_locations
     SET name = ?, location_type = ?, address = ?, city = ?, state_region = ?, postal_code = ?,
         phone = ?, website_url = ?, notes = ?, active = ?
     WHERE id = ? AND user_id = ?'
  );
  $stmt->bind_param('sssssssssiii', $name, $locationType, $address, $city, $stateRegion, $postalCode, $phone, $websiteUrl, $notes, $active, $id, $userId);
  $stmt->execute();
  $stmt->close();
}

// ---- Phase 2: Activities ----

function listActivities(int $userId): array {
  $stmt = db()->prepare(
    'SELECT a.id, a.name, a.category_id, c.name AS category_name, a.description,
            a.typical_duration_minutes, a.productive, a.billable_eligible,
            a.default_location_id, l.name AS location_name, a.quick_log, a.active
     FROM lt_activities a
     LEFT JOIN lt_categories c ON c.id = a.category_id
     LEFT JOIN lt_locations l ON l.id = a.default_location_id
     WHERE a.user_id = ?
     ORDER BY a.active DESC, a.name'
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'Name' => (string)$r['name'],
      'CategoryId' => $r['category_id'] !== null ? (int)$r['category_id'] : null,
      'CategoryName' => (string)($r['category_name'] ?? ''),
      'Description' => (string)($r['description'] ?? ''),
      'TypicalDurationMinutes' => $r['typical_duration_minutes'] !== null ? (int)$r['typical_duration_minutes'] : null,
      'Productive' => (bool)$r['productive'],
      'BillableEligible' => (bool)$r['billable_eligible'],
      'DefaultLocationId' => $r['default_location_id'] !== null ? (int)$r['default_location_id'] : null,
      'DefaultLocationName' => (string)($r['location_name'] ?? ''),
      'QuickLog' => (bool)$r['quick_log'],
      'Active' => (bool)$r['active'],
    ];
  }
  $stmt->close();
  return $out;
}

function activityFields(int $userId, array $b): array {
  $name = trim((string)($b['name'] ?? ''));
  $categoryId = ownedId('lt_categories', isset($b['categoryId']) && $b['categoryId'] !== '' ? (int)$b['categoryId'] : null, $userId);
  $description = nullIfEmpty((string)($b['description'] ?? ''));
  $typicalDuration = isset($b['typicalDurationMinutes']) && $b['typicalDurationMinutes'] !== ''
    ? max(0, (int)$b['typicalDurationMinutes']) : null;
  $productive = !empty($b['productive']) ? 1 : 0;
  $billableEligible = !empty($b['billableEligible']) ? 1 : 0;
  $defaultLocationId = ownedId('lt_locations', isset($b['defaultLocationId']) && $b['defaultLocationId'] !== '' ? (int)$b['defaultLocationId'] : null, $userId);
  $quickLog = !empty($b['quickLog']) ? 1 : 0;
  return [$name, $categoryId, $description, $typicalDuration, $productive, $billableEligible, $defaultLocationId, $quickLog];
}

function addActivity(int $userId, array $b): int {
  [$name, $categoryId, $description, $typicalDuration, $productive, $billableEligible, $defaultLocationId, $quickLog] = activityFields($userId, $b);
  if ($name === '') { fail('Activity name is required'); }
  try {
    $stmt = db()->prepare(
      'INSERT INTO lt_activities
        (user_id, name, category_id, description, typical_duration_minutes, productive, billable_eligible, default_location_id, quick_log)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isisiiiii', $userId, $name, $categoryId, $description, $typicalDuration, $productive, $billableEligible, $defaultLocationId, $quickLog);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'activity');
  }
}

function updateActivity(int $userId, int $id, array $b): void {
  [$name, $categoryId, $description, $typicalDuration, $productive, $billableEligible, $defaultLocationId, $quickLog] = activityFields($userId, $b);
  if ($name === '') { fail('Activity name is required'); }
  $active = !empty($b['active']) ? 1 : 0;
  try {
    $stmt = db()->prepare(
      'UPDATE lt_activities
       SET name = ?, category_id = ?, description = ?, typical_duration_minutes = ?,
           productive = ?, billable_eligible = ?, default_location_id = ?, quick_log = ?, active = ?
       WHERE id = ? AND user_id = ?'
    );
    $stmt->bind_param('sisiiiiiiii', $name, $categoryId, $description, $typicalDuration, $productive, $billableEligible, $defaultLocationId, $quickLog, $active, $id, $userId);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'activity');
  }
}

// ---- Phase 2: Activity Log ----

// $date is 'YYYY-MM-DD', $time is 'HH:MM' or empty/null.
function combineDateTime(string $date, $time): ?string {
  $time = trim((string)$time);
  if ($time === '' || !preg_match('/^\d{1,2}:\d{2}$/', $time)) { return null; }
  return $date . ' ' . $time . ':00';
}

function minutesBetween(?string $start, ?string $end): ?int {
  if ($start === null || $end === null) { return null; }
  $s = strtotime($start);
  $e = strtotime($end);
  if ($s === false || $e === false || $e <= $s) { return null; }
  return (int)round(($e - $s) / 60);
}

function listActivityLog(int $userId, string $from, string $to, ?int $activityId): array {
  $sql = 'SELECT al.id, al.activity_id, a.name AS activity_name, al.activity_date,
                 al.start_time, al.end_time, al.duration_minutes, al.location_id,
                 l.name AS location_name, l.address AS location_address, al.productive, al.billable, al.shared_life,
                 al.cost_amount, al.notes, al.learning_project_id, lp.name AS learning_project_name,
                 al.learning_mode,
                 (SELECT GROUP_CONCAT(alp.person_id) FROM lt_activity_log_person alp WHERE alp.activity_log_id = al.id) AS person_ids,
                 (SELECT GROUP_CONCAT(p.display_name SEPARATOR \'||\') FROM lt_activity_log_person alp2 JOIN lt_people p ON p.id = alp2.person_id WHERE alp2.activity_log_id = al.id) AS person_names,
                 (SELECT GROUP_CONCAT(alt.tag_id) FROM lt_activity_log_tag alt WHERE alt.activity_log_id = al.id) AS tag_ids,
                 (SELECT GROUP_CONCAT(t.name SEPARATOR \'||\') FROM lt_activity_log_tag alt2 JOIN lt_tags t ON t.id = alt2.tag_id WHERE alt2.activity_log_id = al.id) AS tag_names
          FROM lt_activity_log al
          JOIN lt_activities a ON a.id = al.activity_id
          LEFT JOIN lt_locations l ON l.id = al.location_id
          LEFT JOIN lt_learning_projects lp ON lp.id = al.learning_project_id
          WHERE al.user_id = ? AND al.activity_date BETWEEN ? AND ?';
  $types = 'iss';
  $params = [$userId, $from, $to];
  if ($activityId !== null) {
    $sql .= ' AND al.activity_id = ?';
    $types .= 'i';
    $params[] = $activityId;
  }
  $sql .= ' ORDER BY al.activity_date DESC, al.start_time IS NULL, al.start_time DESC, al.id DESC';
  $stmt = db()->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'ActivityId' => (int)$r['activity_id'],
      'ActivityName' => (string)$r['activity_name'],
      'ActivityDate' => (string)$r['activity_date'],
      'StartTime' => $r['start_time'],
      'EndTime' => $r['end_time'],
      'DurationMinutes' => $r['duration_minutes'] !== null ? (int)$r['duration_minutes'] : null,
      'LocationId' => $r['location_id'] !== null ? (int)$r['location_id'] : null,
      'LocationName' => (string)($r['location_name'] ?? ''),
      'LocationAddress' => (string)($r['location_address'] ?? ''),
      'Productive' => (bool)$r['productive'],
      'Billable' => (bool)$r['billable'],
      'SharedLife' => (bool)$r['shared_life'],
      'CostAmount' => $r['cost_amount'] !== null ? (float)$r['cost_amount'] : null,
      'Notes' => (string)($r['notes'] ?? ''),
      'LearningProjectId' => $r['learning_project_id'] !== null ? (int)$r['learning_project_id'] : null,
      'LearningProjectName' => (string)($r['learning_project_name'] ?? ''),
      'LearningMode' => $r['learning_mode'],
      'PersonIds' => $r['person_ids'] ? array_map('intval', explode(',', $r['person_ids'])) : [],
      'PersonNames' => $r['person_names'] ? explode('||', $r['person_names']) : [],
      'TagIds' => $r['tag_ids'] ? array_map('intval', explode(',', $r['tag_ids'])) : [],
      'TagNames' => $r['tag_names'] ? explode('||', $r['tag_names']) : [],
    ];
  }
  $stmt->close();
  return $out;
}

function activityLogFields(int $userId, array $b): array {
  $activityId = ownedId('lt_activities', isset($b['activityId']) && $b['activityId'] !== '' ? (int)$b['activityId'] : null, $userId);
  $date = trim((string)($b['activityDate'] ?? ''));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { fail('A valid activity date is required'); }
  $startTime = combineDateTime($date, $b['startTime'] ?? null);
  $endTime = combineDateTime($date, $b['endTime'] ?? null);
  $duration = minutesBetween($startTime, $endTime);
  if ($duration === null && isset($b['durationMinutes']) && $b['durationMinutes'] !== '') {
    $duration = max(0, (int)$b['durationMinutes']);
  }
  $locationId = ownedId('lt_locations', isset($b['locationId']) && $b['locationId'] !== '' ? (int)$b['locationId'] : null, $userId);
  $productive = !empty($b['productive']) ? 1 : 0;
  $billable = !empty($b['billable']) ? 1 : 0;
  $sharedLife = !empty($b['sharedLife']) ? 1 : 0;
  $costAmount = (isset($b['costAmount']) && $b['costAmount'] !== '') ? (float)$b['costAmount'] : null;
  $notes = nullIfEmpty((string)($b['notes'] ?? ''));
  $learningProjectId = ownedId('lt_learning_projects', isset($b['learningProjectId']) && $b['learningProjectId'] !== '' ? (int)$b['learningProjectId'] : null, $userId);
  $learningMode = $learningProjectId !== null ? normEnum((string)($b['learningMode'] ?? ''), LEARNING_MODES, 'Learn') : null;
  $personIds = ownedIdsIn('lt_people', $userId, $b['personIds'] ?? []);
  $tagIds = ownedIdsIn('lt_tags', $userId, $b['tagIds'] ?? []);
  return [$activityId, $date, $startTime, $endTime, $duration, $locationId, $productive, $billable,
          $sharedLife, $costAmount, $notes, $learningProjectId, $learningMode, $personIds, $tagIds];
}

function addActivityLog(int $userId, array $b): int {
  [$activityId, $date, $startTime, $endTime, $duration, $locationId, $productive, $billable,
   $sharedLife, $costAmount, $notes, $learningProjectId, $learningMode, $personIds, $tagIds] = activityLogFields($userId, $b);
  if ($activityId === null) { fail('A valid activity is required'); }
  $pairs = [
    ['i', $userId], ['i', $activityId], ['s', $date], ['s', $startTime], ['s', $endTime],
    ['i', $duration], ['i', $locationId], ['i', $productive], ['i', $billable], ['i', $sharedLife],
    ['d', $costAmount], ['s', $notes], ['i', $learningProjectId], ['s', $learningMode],
  ];
  $stmt = db()->prepare(
    'INSERT INTO lt_activity_log
      (user_id, activity_id, activity_date, start_time, end_time, duration_minutes, location_id,
       productive, billable, shared_life, cost_amount, notes, learning_project_id, learning_mode)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->bind_param(implode('', array_column($pairs, 0)), ...array_column($pairs, 1));
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  syncBridge('lt_activity_log_person', 'activity_log_id', $id, 'person_id', $personIds);
  syncBridge('lt_activity_log_tag', 'activity_log_id', $id, 'tag_id', $tagIds);
  return $id;
}

function updateActivityLog(int $userId, int $id, array $b): void {
  [$activityId, $date, $startTime, $endTime, $duration, $locationId, $productive, $billable,
   $sharedLife, $costAmount, $notes, $learningProjectId, $learningMode, $personIds, $tagIds] = activityLogFields($userId, $b);
  if ($activityId === null) { fail('A valid activity is required'); }
  $pairs = [
    ['i', $activityId], ['s', $date], ['s', $startTime], ['s', $endTime], ['i', $duration],
    ['i', $locationId], ['i', $productive], ['i', $billable], ['i', $sharedLife], ['d', $costAmount],
    ['s', $notes], ['i', $learningProjectId], ['s', $learningMode], ['i', $id], ['i', $userId],
  ];
  $stmt = db()->prepare(
    'UPDATE lt_activity_log
     SET activity_id = ?, activity_date = ?, start_time = ?, end_time = ?, duration_minutes = ?,
         location_id = ?, productive = ?, billable = ?, shared_life = ?, cost_amount = ?, notes = ?,
         learning_project_id = ?, learning_mode = ?
     WHERE id = ? AND user_id = ?'
  );
  $stmt->bind_param(implode('', array_column($pairs, 0)), ...array_column($pairs, 1));
  $stmt->execute();
  $stmt->close();
  syncBridge('lt_activity_log_person', 'activity_log_id', $id, 'person_id', $personIds);
  syncBridge('lt_activity_log_tag', 'activity_log_id', $id, 'tag_id', $tagIds);
}

function deleteActivityLog(int $userId, int $id): void {
  $stmt = db()->prepare('DELETE FROM lt_activity_log WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();
}

function quickLogActivity(int $userId, int $activityId): array {
  $stmt = db()->prepare(
    'SELECT id, name, typical_duration_minutes, productive, billable_eligible, default_location_id
     FROM lt_activities WHERE id = ? AND user_id = ? AND active = 1'
  );
  $stmt->bind_param('ii', $activityId, $userId);
  $stmt->execute();
  $activity = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$activity) { fail('Activity not found'); }

  $duration = $activity['typical_duration_minutes'] !== null ? (int)$activity['typical_duration_minutes'] : null;
  $locationId = $activity['default_location_id'] !== null ? (int)$activity['default_location_id'] : null;
  $productive = (int)$activity['productive'];
  $billable = (int)$activity['billable_eligible'];

  $ins = db()->prepare(
    'INSERT INTO lt_activity_log (user_id, activity_id, activity_date, duration_minutes, location_id, productive, billable)
     VALUES (?, ?, CURDATE(), ?, ?, ?, ?)'
  );
  $ins->bind_param('iiiiii', $userId, $activityId, $duration, $locationId, $productive, $billable);
  $ins->execute();
  $id = $ins->insert_id;
  $ins->close();
  return ['id' => $id, 'activityName' => $activity['name']];
}

// ---- Phase 3: Goals, Cadence, and Weekly Progress ----

const GOAL_TYPES = ['Minimum', 'Target', 'Maximum', 'TrackOnly'];
const CADENCE_TYPES = ['Daily', 'Weekly', 'Monthly'];
const DAY_TYPES = ['Home', 'Local Outing', 'Travel', 'Vacation', 'Sick', 'Special Event'];

function normEnum(string $v, array $allowed, string $default): string {
  foreach ($allowed as $a) { if (strcasecmp($a, $v) === 0) { return $a; } }
  return $default;
}

function listGoals(int $userId): array {
  // Correlated subqueries rather than joining both bridge tables directly --
  // two LEFT JOINs here would cross-multiply activity_ids x season_ids.
  $stmt = db()->prepare(
    'SELECT g.id, g.name, g.goal_type, g.cadence_type, g.target_value, g.weight, g.active,
            (SELECT GROUP_CONCAT(ga.activity_id) FROM lt_goal_activity ga WHERE ga.goal_id = g.id) AS activity_ids,
            (SELECT GROUP_CONCAT(gs.season_id) FROM lt_goal_season gs WHERE gs.goal_id = g.id) AS season_ids
     FROM lt_goals g
     WHERE g.user_id = ?
     ORDER BY g.active DESC, g.name'
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'Name' => (string)$r['name'],
      'GoalType' => (string)$r['goal_type'],
      'CadenceType' => (string)$r['cadence_type'],
      'TargetValue' => $r['target_value'] !== null ? (float)$r['target_value'] : null,
      'Weight' => (float)$r['weight'],
      'Active' => (bool)$r['active'],
      'ActivityIds' => $r['activity_ids'] ? array_map('intval', explode(',', $r['activity_ids'])) : [],
      'SeasonIds' => $r['season_ids'] ? array_map('intval', explode(',', $r['season_ids'])) : [],
    ];
  }
  $stmt->close();
  return $out;
}

// Validates a list of ids as owned by $userId in $table, dropping any that
// aren't (bad/foreign selections from a multi-select are silently ignored
// rather than failing the whole request).
function ownedIdsIn(string $table, int $userId, $ids): array {
  if (!is_array($ids)) { return []; }
  $out = [];
  foreach ($ids as $id) {
    $owned = ownedId($table, (int)$id, $userId);
    if ($owned !== null) { $out[] = $owned; }
  }
  return array_values(array_unique($out));
}

// Replaces every row for $ownId in a many-to-many bridge table with exactly
// $otherIds -- the standard "this owner now links to exactly this set"
// pattern used by Goal<->Activity (Phase 3) and Log<->Person/Tag (Phase 4).
function syncBridge(string $table, string $ownColumn, int $ownId, string $otherColumn, array $otherIds): void {
  $del = db()->prepare("DELETE FROM `$table` WHERE `$ownColumn` = ?");
  $del->bind_param('i', $ownId);
  $del->execute();
  $del->close();
  if (!$otherIds) { return; }
  $ins = db()->prepare("INSERT INTO `$table` (`$ownColumn`, `$otherColumn`) VALUES (?, ?)");
  foreach ($otherIds as $otherId) {
    $ins->bind_param('ii', $ownId, $otherId);
    $ins->execute();
  }
  $ins->close();
}

function goalWeight(array $b): float {
  $w = (isset($b['weight']) && $b['weight'] !== '') ? (float)$b['weight'] : 1.0;
  return $w > 0 ? $w : 1.0;
}

function addGoal(int $userId, array $b): int {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Goal name is required'); }
  $goalType = normEnum((string)($b['goalType'] ?? ''), GOAL_TYPES, 'TrackOnly');
  $cadenceType = normEnum((string)($b['cadenceType'] ?? ''), CADENCE_TYPES, 'Weekly');
  $targetValue = (isset($b['targetValue']) && $b['targetValue'] !== '') ? (float)$b['targetValue'] : null;
  $weight = goalWeight($b);
  try {
    $stmt = db()->prepare(
      'INSERT INTO lt_goals (user_id, name, goal_type, cadence_type, target_value, weight) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssdd', $userId, $name, $goalType, $cadenceType, $targetValue, $weight);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'goal');
  }
  syncBridge('lt_goal_activity', 'goal_id', $id, 'activity_id', ownedIdsIn('lt_activities', $userId, $b['activityIds'] ?? []));
  syncBridge('lt_goal_season', 'goal_id', $id, 'season_id', ownedIdsIn('lt_seasons', $userId, $b['seasonIds'] ?? []));
  return $id;
}

function updateGoal(int $userId, int $id, array $b): void {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Goal name is required'); }
  $goalType = normEnum((string)($b['goalType'] ?? ''), GOAL_TYPES, 'TrackOnly');
  $cadenceType = normEnum((string)($b['cadenceType'] ?? ''), CADENCE_TYPES, 'Weekly');
  $targetValue = (isset($b['targetValue']) && $b['targetValue'] !== '') ? (float)$b['targetValue'] : null;
  $weight = goalWeight($b);
  $active = !empty($b['active']) ? 1 : 0;
  try {
    $stmt = db()->prepare(
      'UPDATE lt_goals SET name = ?, goal_type = ?, cadence_type = ?, target_value = ?, weight = ?, active = ?
       WHERE id = ? AND user_id = ?'
    );
    $stmt->bind_param('sssddiii', $name, $goalType, $cadenceType, $targetValue, $weight, $active, $id, $userId);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'goal');
  }
  syncBridge('lt_goal_activity', 'goal_id', $id, 'activity_id', ownedIdsIn('lt_activities', $userId, $b['activityIds'] ?? []));
  syncBridge('lt_goal_season', 'goal_id', $id, 'season_id', ownedIdsIn('lt_seasons', $userId, $b['seasonIds'] ?? []));
}

// ---- Phase 6: Seasons ----

const MONTH_DAY_RE = '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';

function listSeasons(int $userId): array {
  $stmt = db()->prepare('SELECT id, name, start_month_day, end_month_day, active FROM lt_seasons WHERE user_id = ? ORDER BY active DESC, name');
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'Name' => (string)$r['name'],
      'StartMonthDay' => (string)$r['start_month_day'],
      'EndMonthDay' => (string)$r['end_month_day'],
      'Active' => (bool)$r['active'],
    ];
  }
  $stmt->close();
  return $out;
}

function seasonMonthDays(array $b): array {
  $start = trim((string)($b['startMonthDay'] ?? ''));
  $end = trim((string)($b['endMonthDay'] ?? ''));
  if (!preg_match(MONTH_DAY_RE, $start) || !preg_match(MONTH_DAY_RE, $end)) {
    fail('Start and end must be valid MM-DD dates');
  }
  return [$start, $end];
}

function addSeason(int $userId, array $b): int {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Season name is required'); }
  [$start, $end] = seasonMonthDays($b);
  try {
    $stmt = db()->prepare('INSERT INTO lt_seasons (user_id, name, start_month_day, end_month_day) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $userId, $name, $start, $end);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'season');
  }
}

function updateSeason(int $userId, int $id, array $b): void {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Season name is required'); }
  [$start, $end] = seasonMonthDays($b);
  $active = !empty($b['active']) ? 1 : 0;
  try {
    $stmt = db()->prepare('UPDATE lt_seasons SET name = ?, start_month_day = ?, end_month_day = ?, active = ? WHERE id = ? AND user_id = ?');
    $stmt->bind_param('sssiii', $name, $start, $end, $active, $id, $userId);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'season');
  }
}

// True if $monthDay ('MM-DD') falls within [$start,$end], handling ranges
// that wrap the year boundary (e.g. '11-15' to '02-15' for a winter season).
function monthDayInRange(string $monthDay, string $start, string $end): bool {
  if ($start <= $end) { return $monthDay >= $start && $monthDay <= $end; }
  return $monthDay >= $start || $monthDay <= $end;
}

// A goal with no linked seasons is always active. One with linked seasons
// is only active while today falls within one of them (spec section 55).
// $seasonsById is a lookup built once per request (see computeGoalProgress/
// computeEngagement) rather than re-querying per goal.
function goalInSeasonNow(array $goal, array $seasonsById): bool {
  if (empty($goal['SeasonIds'])) { return true; }
  $todayMd = (new DateTime('today'))->format('m-d');
  foreach ($goal['SeasonIds'] as $sid) {
    $s = $seasonsById[$sid] ?? null;
    if ($s && $s['Active'] && monthDayInRange($todayMd, $s['StartMonthDay'], $s['EndMonthDay'])) {
      return true;
    }
  }
  return false;
}

function seasonsById(int $userId): array {
  $out = [];
  foreach (listSeasons($userId) as $s) { $out[$s['Id']] = $s; }
  return $out;
}

function weekRange(): array {
  $today = new DateTime('today');
  $dow = (int)$today->format('N'); // 1 = Monday .. 7 = Sunday
  $start = (clone $today)->modify('-' . ($dow - 1) . ' days');
  $end = (clone $start)->modify('+6 days');
  return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function monthRange(): array {
  $first = new DateTime('first day of this month');
  $last = new DateTime('last day of this month');
  return [$first->format('Y-m-d'), $last->format('Y-m-d')];
}

function rolling4WeekRange(): array {
  $end = new DateTime('today');
  $start = (clone $end)->modify('-27 days');
  return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function quarterRange(): array {
  $today = new DateTime('today');
  $quarterStartMonth = intdiv((int)$today->format('n') - 1, 3) * 3 + 1;
  $first = new DateTime($today->format('Y') . '-' . $quarterStartMonth . '-01');
  $last = (clone $first)->modify('+3 months')->modify('-1 day');
  return [$first->format('Y-m-d'), $last->format('Y-m-d')];
}

function yearRange(): array {
  $year = (new DateTime('today'))->format('Y');
  return ["$year-01-01", "$year-12-31"];
}

function applicableDaysInRange(string $start, string $end, int $userId): int {
  $total = (int)(new DateTime($start))->diff(new DateTime($end))->days + 1;
  $stmt = db()->prepare(
    'SELECT COUNT(*) FROM lt_day_status
     WHERE user_id = ? AND calendar_date BETWEEN ? AND ? AND productive_goal_applies = 0'
  );
  $stmt->bind_param('iss', $userId, $start, $end);
  $stmt->execute();
  $excluded = (int)$stmt->get_result()->fetch_row()[0];
  $stmt->close();
  return max(0, $total - $excluded);
}

function goalActualCount(int $userId, array $activityIds, string $start, string $end): int {
  if (!$activityIds) { return 0; }
  $placeholders = implode(',', array_fill(0, count($activityIds), '?'));
  $types = 'iss' . str_repeat('i', count($activityIds));
  $params = array_merge([$userId, $start, $end], $activityIds);
  $stmt = db()->prepare(
    "SELECT COUNT(*) FROM lt_activity_log
     WHERE user_id = ? AND activity_date BETWEEN ? AND ? AND activity_id IN ($placeholders)"
  );
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $count = (int)$stmt->get_result()->fetch_row()[0];
  $stmt->close();
  return $count;
}

function computeGoalProgress(int $userId): array {
  $seasons = seasonsById($userId);
  $goals = array_filter(listGoals($userId), fn($g) => $g['Active'] && goalInSeasonNow($g, $seasons));
  [$weekStart, $weekEnd] = weekRange();
  [$monthStart, $monthEnd] = monthRange();
  $out = [];
  foreach ($goals as $g) {
    $isMonthly = $g['CadenceType'] === 'Monthly';
    [$start, $end] = $isMonthly ? [$monthStart, $monthEnd] : [$weekStart, $weekEnd];
    $target = $g['TargetValue'] ?? 0;
    $expected = $target;
    if ($g['CadenceType'] === 'Daily') {
      $expected = $target * applicableDaysInRange($start, $end, $userId);
    }
    $actual = goalActualCount($userId, $g['ActivityIds'], $start, $end);

    if ($g['GoalType'] === 'TrackOnly') {
      $status = 'Tracked';
      $percent = $expected > 0 ? min(100.0, round($actual / $expected * 100)) : ($actual > 0 ? 100.0 : 0.0);
    } elseif ($g['GoalType'] === 'Maximum') {
      $percent = $expected > 0 ? round($actual / $expected * 100) : 0.0;
      $status = $actual <= $expected ? 'Within limit' : 'Over';
    } else { // Minimum or Target
      $percent = $expected > 0 ? min(100.0, round($actual / $expected * 100)) : ($actual > 0 ? 100.0 : 0.0);
      $status = $actual >= $expected ? 'Met' : 'Behind';
    }

    $out[] = [
      'GoalId' => $g['Id'],
      'Name' => $g['Name'],
      'GoalType' => $g['GoalType'],
      'CadenceType' => $g['CadenceType'],
      'PeriodStart' => $start,
      'PeriodEnd' => $end,
      'Actual' => $actual,
      'Expected' => $expected,
      'Percent' => $percent,
      'Status' => $status,
      'HasActivities' => !empty($g['ActivityIds']),
    ];
  }
  return $out;
}

// ---- Phase 5: Engagement Scoring and Heat Maps ----
//
// computeGoalProgress() above answers "how am I doing this week/month, per
// goal, on its own native cadence" (the Dashboard). Engagement answers a
// different question -- "what's my overall score for ANY period" (a week, a
// month, a quarter, a year) -- which means every goal's target has to be
// scaled to whatever period is being asked about, regardless of the goal's
// own cadence. That's what goalExpectedInPeriod() does.

// Scales a goal's target_value to an arbitrary [$start,$end] period. Daily
// goals scale exactly (via applicableDaysInRange, so exceptions still
// apply); Weekly/Monthly goals scale by the ratio of period length to a
// week/month -- approximate for odd period lengths (a quarter isn't exactly
// 13 weeks), which is an acceptable trade for a personal app (spec section
// 147.2: let real usage tell us if finer precision is ever worth it).
function goalExpectedInPeriod(array $goal, string $start, string $end, int $userId): float {
  $target = $goal['TargetValue'] ?? 0;
  if ($target <= 0) { return 0.0; }
  $days = (int)(new DateTime($start))->diff(new DateTime($end))->days + 1;
  switch ($goal['CadenceType']) {
    case 'Daily':
      return $target * applicableDaysInRange($start, $end, $userId);
    case 'Monthly':
      return $target * ($days / 30.44); // 365.25 / 12
    default: // Weekly
      return $target * ($days / 7);
  }
}

// A goal's contribution to the overall score, always on a 0-100 scale
// where higher is better -- even for Maximum goals, where "over" declines
// from 100 rather than climbing past it (spec section 11 rule 1: individual
// goals cap at 100 so no goal can inflate the overall score by overachieving).
function goalScorePercent(array $goal, float $actual, float $expected): float {
  if ($expected <= 0) { return $actual > 0 ? 100.0 : 0.0; }
  if ($goal['GoalType'] === 'Maximum') {
    if ($actual <= $expected) { return 100.0; }
    $overPercent = (($actual - $expected) / $expected) * 100;
    return max(0.0, 100.0 - $overPercent);
  }
  return min(100.0, ($actual / $expected) * 100); // Minimum or Target
}

// The whole engagement calculation for one period: a weighted average of
// every active, scoreable goal's ScorePercent, plus the full breakdown so
// the score is never a black box (spec section 36/147.5 -- the user should
// always be able to see what was expected, what was completed, and how
// each goal was weighted). TrackOnly goals are excluded from the score
// (spec section 136 point 8) but nothing stops them from being tracked --
// they just don't move the number.
function computeEngagement(int $userId, string $start, string $end): array {
  // goalInSeasonNow() checks today's date regardless of $start/$end -- fine
  // for Week/Rolling4Weeks/Month, an approximation for Quarter/Year (a
  // season that changes mid-quarter won't be split within that quarter's
  // score). Acceptable trade for a personal app; revisit if it matters.
  $seasons = seasonsById($userId);
  $goals = array_filter(listGoals($userId), fn($g) => $g['Active'] && $g['GoalType'] !== 'TrackOnly' && goalInSeasonNow($g, $seasons));
  $breakdown = [];
  $weightedSum = 0.0;
  $weightTotal = 0.0;
  foreach ($goals as $g) {
    $expected = goalExpectedInPeriod($g, $start, $end, $userId);
    $actual = goalActualCount($userId, $g['ActivityIds'], $start, $end);
    $score = goalScorePercent($g, $actual, $expected);
    $weight = $g['Weight'];
    $weightedSum += $score * $weight;
    $weightTotal += $weight;
    $breakdown[] = [
      'GoalId' => $g['Id'],
      'Name' => $g['Name'],
      'GoalType' => $g['GoalType'],
      'CadenceType' => $g['CadenceType'],
      'Weight' => $weight,
      'Actual' => $actual,
      'Expected' => round($expected, 2),
      'ScorePercent' => round($score, 1),
      'HasActivities' => !empty($g['ActivityIds']),
    ];
  }
  return [
    'PeriodStart' => $start,
    'PeriodEnd' => $end,
    'Overall' => $weightTotal > 0 ? round($weightedSum / $weightTotal, 1) : null,
    'Goals' => $breakdown,
  ];
}

// The five period views the spec's Today/Weekly/Monthly/Quarterly reviews
// call for (sections 32-34), all from the one computeEngagement() function.
function engagementSummary(int $userId): array {
  [$wStart, $wEnd] = weekRange();
  [$r4Start, $r4End] = rolling4WeekRange();
  [$mStart, $mEnd] = monthRange();
  [$qStart, $qEnd] = quarterRange();
  [$yStart, $yEnd] = yearRange();
  return [
    'Week' => computeEngagement($userId, $wStart, $wEnd),
    'Rolling4Weeks' => computeEngagement($userId, $r4Start, $r4End),
    'Month' => computeEngagement($userId, $mStart, $mEnd),
    'Quarter' => computeEngagement($userId, $qStart, $qEnd),
    'Year' => computeEngagement($userId, $yStart, $yEnd),
  ];
}

// The heat map: one row per week for the last $weeks weeks, each with the
// overall score plus every goal's own score that week -- reveals patterns a
// single current-period number can't (spec section 12).
function engagementHeatmap(int $userId, int $weeks): array {
  $today = new DateTime('today');
  $dow = (int)$today->format('N');
  $thisWeekStart = (clone $today)->modify('-' . ($dow - 1) . ' days');
  $rows = [];
  for ($i = $weeks - 1; $i >= 0; $i--) {
    $start = (clone $thisWeekStart)->modify('-' . ($i * 7) . ' days');
    $end = (clone $start)->modify('+6 days');
    $eng = computeEngagement($userId, $start->format('Y-m-d'), $end->format('Y-m-d'));
    $rows[] = [
      'WeekStart' => $eng['PeriodStart'],
      'WeekEnd' => $eng['PeriodEnd'],
      'Overall' => $eng['Overall'],
      'Goals' => array_map(fn($g) => [
        'GoalId' => $g['GoalId'],
        'Name' => $g['Name'],
        'ScorePercent' => $g['ScorePercent'],
      ], $eng['Goals']),
    ];
  }
  return $rows;
}

function getDayStatus(int $userId, string $date): array {
  $stmt = db()->prepare('SELECT day_type, productive_goal_applies, notes FROM lt_day_status WHERE user_id = ? AND calendar_date = ?');
  $stmt->bind_param('is', $userId, $date);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) {
    return ['DayType' => 'Home', 'ProductiveGoalApplies' => true, 'Notes' => '', 'IsSet' => false];
  }
  return [
    'DayType' => $row['day_type'],
    'ProductiveGoalApplies' => (bool)$row['productive_goal_applies'],
    'Notes' => (string)($row['notes'] ?? ''),
    'IsSet' => true,
  ];
}

function setDayStatus(int $userId, string $date, string $dayType, string $notes): void {
  $dayType = normEnum($dayType, DAY_TYPES, 'Home');
  $applies = in_array($dayType, ['Home', 'Local Outing'], true) ? 1 : 0;
  $notesVal = nullIfEmpty($notes);
  $stmt = db()->prepare(
    'INSERT INTO lt_day_status (user_id, calendar_date, day_type, productive_goal_applies, notes)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE day_type = VALUES(day_type), productive_goal_applies = VALUES(productive_goal_applies), notes = VALUES(notes)'
  );
  $stmt->bind_param('issis', $userId, $date, $dayType, $applies, $notesVal);
  $stmt->execute();
  $stmt->close();
}

// ---- Phase 4: People, Shared Life, and Learning ----

const LEARNING_MODES = ['Learn', 'Apply'];

function listPeople(int $userId): array {
  $stmt = db()->prepare('SELECT id, display_name, relationship_type, active FROM lt_people WHERE user_id = ? ORDER BY active DESC, display_name');
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'DisplayName' => (string)$r['display_name'],
      'RelationshipType' => (string)($r['relationship_type'] ?? ''),
      'Active' => (bool)$r['active'],
    ];
  }
  $stmt->close();
  return $out;
}

function addPerson(int $userId, array $b): int {
  $name = trim((string)($b['displayName'] ?? ''));
  if ($name === '') { fail('Person name is required'); }
  $rel = nullIfEmpty((string)($b['relationshipType'] ?? ''));
  $stmt = db()->prepare('INSERT INTO lt_people (user_id, display_name, relationship_type) VALUES (?, ?, ?)');
  $stmt->bind_param('iss', $userId, $name, $rel);
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return $id;
}

function updatePerson(int $userId, int $id, array $b): void {
  $name = trim((string)($b['displayName'] ?? ''));
  if ($name === '') { fail('Person name is required'); }
  $rel = nullIfEmpty((string)($b['relationshipType'] ?? ''));
  $active = !empty($b['active']) ? 1 : 0;
  $stmt = db()->prepare('UPDATE lt_people SET display_name = ?, relationship_type = ?, active = ? WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ssiii', $name, $rel, $active, $id, $userId);
  $stmt->execute();
  $stmt->close();
}

function listTags(int $userId): array {
  $stmt = db()->prepare('SELECT id, name, active FROM lt_tags WHERE user_id = ? ORDER BY active DESC, name');
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = ['Id' => (int)$r['id'], 'Name' => (string)$r['name'], 'Active' => (bool)$r['active']];
  }
  $stmt->close();
  return $out;
}

function addTag(int $userId, array $b): int {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Tag name is required'); }
  try {
    $stmt = db()->prepare('INSERT INTO lt_tags (user_id, name) VALUES (?, ?)');
    $stmt->bind_param('is', $userId, $name);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'tag');
  }
}

function updateTag(int $userId, int $id, array $b): void {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Tag name is required'); }
  $active = !empty($b['active']) ? 1 : 0;
  try {
    $stmt = db()->prepare('UPDATE lt_tags SET name = ?, active = ? WHERE id = ? AND user_id = ?');
    $stmt->bind_param('siii', $name, $active, $id, $userId);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'tag');
  }
}

function listLearningProjects(int $userId): array {
  $stmt = db()->prepare('SELECT id, name, description, active FROM lt_learning_projects WHERE user_id = ? ORDER BY active DESC, name');
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'Name' => (string)$r['name'],
      'Description' => (string)($r['description'] ?? ''),
      'Active' => (bool)$r['active'],
    ];
  }
  $stmt->close();
  return $out;
}

function addLearningProject(int $userId, array $b): int {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Learning project name is required'); }
  $description = nullIfEmpty((string)($b['description'] ?? ''));
  try {
    $stmt = db()->prepare('INSERT INTO lt_learning_projects (user_id, name, description) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $name, $description);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'learning project');
  }
}

function updateLearningProject(int $userId, int $id, array $b): void {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') { fail('Learning project name is required'); }
  $description = nullIfEmpty((string)($b['description'] ?? ''));
  $active = !empty($b['active']) ? 1 : 0;
  try {
    $stmt = db()->prepare('UPDATE lt_learning_projects SET name = ?, description = ?, active = ? WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ssiii', $name, $description, $active, $id, $userId);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    duplicateNameFail($e, 'learning project');
  }
}

function sharedLifeSummary(int $userId): array {
  [$weekStart, $weekEnd] = weekRange();
  [$monthStart, $monthEnd] = monthRange();
  $countIn = function (string $start, string $end) use ($userId) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM lt_activity_log WHERE user_id = ? AND shared_life = 1 AND activity_date BETWEEN ? AND ?');
    $stmt->bind_param('iss', $userId, $start, $end);
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $c;
  };
  return ['ThisWeek' => $countIn($weekStart, $weekEnd), 'ThisMonth' => $countIn($monthStart, $monthEnd)];
}

// ---- Phase 6: Planning (PlannedEvent + bulk Day Status) ----

const PLANNED_EVENT_STATUSES = ['Planned', 'Completed', 'Cancelled'];

// Like combineDateTime() but the result is required -- a PlannedEvent
// always needs a real start time (spec section 65), unlike an ActivityLog
// entry which can be dateless.
function requireDateTime(string $date, $time, string $label): string {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { fail("A valid $label date is required"); }
  $combined = combineDateTime($date, $time);
  if ($combined === null) { fail("A valid $label time is required"); }
  return $combined;
}

function listPlannedEvents(int $userId, ?string $status): array {
  $sql = 'SELECT pe.id, pe.activity_id, a.name AS activity_name, pe.title, pe.start_datetime,
                 pe.end_datetime, pe.location_id, l.name AS location_name, l.address AS location_address,
                 pe.status, pe.notes
          FROM lt_planned_events pe
          LEFT JOIN lt_activities a ON a.id = pe.activity_id
          LEFT JOIN lt_locations l ON l.id = pe.location_id
          WHERE pe.user_id = ?';
  $types = 'i';
  $params = [$userId];
  if ($status !== null) {
    $sql .= ' AND pe.status = ?';
    $types .= 's';
    $params[] = $status;
  }
  $sql .= ' ORDER BY pe.start_datetime ASC';
  $stmt = db()->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) {
    $out[] = [
      'Id' => (int)$r['id'],
      'ActivityId' => $r['activity_id'] !== null ? (int)$r['activity_id'] : null,
      'ActivityName' => (string)($r['activity_name'] ?? ''),
      'Title' => (string)$r['title'],
      'StartDateTime' => $r['start_datetime'],
      'EndDateTime' => $r['end_datetime'],
      'LocationId' => $r['location_id'] !== null ? (int)$r['location_id'] : null,
      'LocationName' => (string)($r['location_name'] ?? ''),
      'LocationAddress' => (string)($r['location_address'] ?? ''),
      'Status' => (string)$r['status'],
      'Notes' => (string)($r['notes'] ?? ''),
    ];
  }
  $stmt->close();
  return $out;
}

function plannedEventFields(int $userId, array $b): array {
  $title = trim((string)($b['title'] ?? ''));
  if ($title === '') { fail('Title is required'); }
  $activityId = ownedId('lt_activities', isset($b['activityId']) && $b['activityId'] !== '' ? (int)$b['activityId'] : null, $userId);
  $startDate = trim((string)($b['startDate'] ?? ''));
  $startDateTime = requireDateTime($startDate, $b['startTime'] ?? null, 'start');
  $endDateTime = null;
  if (!empty($b['endTime'])) {
    $endDate = trim((string)($b['endDate'] ?? '')) ?: $startDate;
    $endDateTime = requireDateTime($endDate, $b['endTime'], 'end');
  }
  $locationId = ownedId('lt_locations', isset($b['locationId']) && $b['locationId'] !== '' ? (int)$b['locationId'] : null, $userId);
  $notes = nullIfEmpty((string)($b['notes'] ?? ''));
  return [$title, $activityId, $startDateTime, $endDateTime, $locationId, $notes];
}

function addPlannedEvent(int $userId, array $b): int {
  [$title, $activityId, $startDateTime, $endDateTime, $locationId, $notes] = plannedEventFields($userId, $b);
  $pairs = [
    ['i', $userId], ['i', $activityId], ['s', $title], ['s', $startDateTime],
    ['s', $endDateTime], ['i', $locationId], ['s', $notes],
  ];
  $stmt = db()->prepare(
    'INSERT INTO lt_planned_events (user_id, activity_id, title, start_datetime, end_datetime, location_id, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->bind_param(implode('', array_column($pairs, 0)), ...array_column($pairs, 1));
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return $id;
}

function updatePlannedEvent(int $userId, int $id, array $b): void {
  [$title, $activityId, $startDateTime, $endDateTime, $locationId, $notes] = plannedEventFields($userId, $b);
  $pairs = [
    ['i', $activityId], ['s', $title], ['s', $startDateTime], ['s', $endDateTime],
    ['i', $locationId], ['s', $notes], ['i', $id], ['i', $userId],
  ];
  $stmt = db()->prepare(
    'UPDATE lt_planned_events
     SET activity_id = ?, title = ?, start_datetime = ?, end_datetime = ?, location_id = ?, notes = ?
     WHERE id = ? AND user_id = ?'
  );
  $stmt->bind_param(implode('', array_column($pairs, 0)), ...array_column($pairs, 1));
  $stmt->execute();
  $stmt->close();
}

function setPlannedEventStatus(int $userId, int $id, string $status): void {
  $status = normEnum($status, PLANNED_EVENT_STATUSES, 'Planned');
  $stmt = db()->prepare('UPDATE lt_planned_events SET status = ? WHERE id = ? AND user_id = ?');
  $stmt->bind_param('sii', $status, $id, $userId);
  $stmt->execute();
  $stmt->close();
}

function deletePlannedEvent(int $userId, int $id): void {
  $stmt = db()->prepare('DELETE FROM lt_planned_events WHERE id = ? AND user_id = ?');
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $stmt->close();
}

// Marks a planned event Completed and, if it has an activity, creates the
// matching ActivityLog entry (spec section 65: a completed PlannedEvent
// may link to an ActivityLog) so completing a plan IS logging it -- no
// double entry required.
function completePlannedEvent(int $userId, int $id): array {
  $stmt = db()->prepare(
    'SELECT id, activity_id, start_datetime, end_datetime, location_id, notes
     FROM lt_planned_events WHERE id = ? AND user_id = ?'
  );
  $stmt->bind_param('ii', $id, $userId);
  $stmt->execute();
  $event = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$event) { fail('Planned event not found'); }

  $logId = null;
  if ($event['activity_id'] !== null) {
    $activityDate = substr($event['start_datetime'], 0, 10);
    $duration = minutesBetween($event['start_datetime'], $event['end_datetime']);
    $locationId = $event['location_id'] !== null ? (int)$event['location_id'] : null;
    $pairs = [
      ['i', $userId], ['i', (int)$event['activity_id']], ['s', $activityDate],
      ['s', $event['start_datetime']], ['s', $event['end_datetime']], ['i', $duration],
      ['i', $locationId], ['s', $event['notes']], ['i', $id],
    ];
    $ins = db()->prepare(
      'INSERT INTO lt_activity_log
        (user_id, activity_id, activity_date, start_time, end_time, duration_minutes, location_id, notes, planned_event_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->bind_param(implode('', array_column($pairs, 0)), ...array_column($pairs, 1));
    $ins->execute();
    $logId = $ins->insert_id;
    $ins->close();
  }

  setPlannedEventStatus($userId, $id, 'Completed');
  return ['logId' => $logId];
}

// Bulk-marks every day in [$from,$to] with the same day type -- the
// practical way to handle a multi-day exception (a trip, an illness)
// without clicking through each day on Today (spec section 31/56,
// implemented via the existing per-day mechanism -- see schema.sql).
function setDayStatusRange(int $userId, string $from, string $to, string $dayType, string $notes): int {
  $start = new DateTime($from);
  $end = new DateTime($to);
  if ($end < $start) { fail('End date must be on or after start date'); }
  $days = (int)$start->diff($end)->days;
  if ($days > 120) { fail('Range is too long (max 120 days)'); }
  for ($i = 0; $i <= $days; $i++) {
    $date = (clone $start)->modify("+$i days")->format('Y-m-d');
    setDayStatus($userId, $date, $dayType, $notes);
  }
  return $days + 1;
}

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? jsonBody() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');

switch ($action) {

  // Phase 1 proof-of-life: confirms the bearer token resolves to a UserID
  // and that UserID has an app_access grant. Domain endpoints start in
  // Phase 2 and will follow this exact requireMember() -> $user['id'] shape.
  case 'ping': {
    $user = requireMember();
    respond([
      'ok' => true,
      'userId' => (int)$user['id'],
      'displayName' => $user['display_name'],
    ]);
  }

  // -- MyDataWorld login (shared with the other apps) --

  case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }
    $stmt = db()->prepare('SELECT id, password_hash, display_name FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user['password_hash'] === null || !password_verify($password, $user['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $ins = db()->prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))');
    $ins->bind_param('sii', $token, $user['id'], $days);
    $ins->execute();
    $ins->close();
    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $stmt->close();
    }
    respond(['ok' => true]);
  }

  case 'whoAmI': {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') { respond(['ok' => false]); }
    $stmt = db()->prepare(
      'SELECT u.username FROM sessions s JOIN users u ON u.id = s.user_id
       WHERE s.token = ? AND s.expires_at > NOW()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    respond($row ? ['ok' => true, 'email' => $row['username']] : ['ok' => false]);
  }

  case 'checkAccess': {
    $user = requireUser();
    if (!hasAppAccess($user)) {
      fail('Not authorized for Life Tempo', 403);
    }
    respond(['ok' => true, 'displayName' => $user['display_name']]);
  }

  // -- Phase 2: Categories --

  case 'categories': {
    $user = requireMember();
    respond(['ok' => true, 'categories' => listCategories((int)$user['id'])]);
  }

  case 'addCategory': {
    $user = requireMember();
    $id = addCategory((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateCategory': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing category id'); }
    updateCategory((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  // -- Phase 2: Locations --

  case 'locations': {
    $user = requireMember();
    respond(['ok' => true, 'locations' => listLocations((int)$user['id'])]);
  }

  case 'addLocation': {
    $user = requireMember();
    $id = addLocation((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateLocation': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing location id'); }
    updateLocation((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  // -- Phase 2: Activities --

  case 'activities': {
    $user = requireMember();
    respond(['ok' => true, 'activities' => listActivities((int)$user['id'])]);
  }

  case 'addActivity': {
    $user = requireMember();
    $id = addActivity((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateActivity': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing activity id'); }
    updateActivity((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  // -- Phase 2: Activity Log --

  case 'activityLog': {
    $user = requireMember();
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-d', strtotime('-30 days')); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $to = date('Y-m-d'); }
    $activityId = (isset($_GET['activityId']) && $_GET['activityId'] !== '') ? (int)$_GET['activityId'] : null;
    respond(['ok' => true, 'entries' => listActivityLog((int)$user['id'], $from, $to, $activityId)]);
  }

  case 'addActivityLog': {
    $user = requireMember();
    $id = addActivityLog((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateActivityLog': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing log id'); }
    updateActivityLog((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  case 'deleteActivityLog': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing log id'); }
    deleteActivityLog((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  case 'quickLog': {
    $user = requireMember();
    $activityId = (int)($body['activityId'] ?? 0);
    if ($activityId <= 0) { fail('Missing activity id'); }
    $result = quickLogActivity((int)$user['id'], $activityId);
    respond(['ok' => true, 'id' => $result['id'], 'activityName' => $result['activityName']]);
  }

  // -- Phase 3: Goals --

  case 'goals': {
    $user = requireMember();
    respond(['ok' => true, 'goals' => listGoals((int)$user['id'])]);
  }

  case 'addGoal': {
    $user = requireMember();
    $id = addGoal((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateGoal': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing goal id'); }
    updateGoal((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  case 'goalProgress': {
    $user = requireMember();
    respond(['ok' => true, 'progress' => computeGoalProgress((int)$user['id'])]);
  }

  // -- Phase 6: Seasons --

  case 'seasons': {
    $user = requireMember();
    respond(['ok' => true, 'seasons' => listSeasons((int)$user['id'])]);
  }

  case 'addSeason': {
    $user = requireMember();
    $id = addSeason((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateSeason': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing season id'); }
    updateSeason((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  // -- Phase 3: Day Status --

  case 'dayStatus': {
    $user = requireMember();
    $date = trim((string)($_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
    respond(['ok' => true, 'date' => $date] + getDayStatus((int)$user['id'], $date));
  }

  case 'setDayStatus': {
    $user = requireMember();
    $date = trim((string)($body['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { fail('A valid date is required'); }
    setDayStatus((int)$user['id'], $date, (string)($body['dayType'] ?? 'Home'), (string)($body['notes'] ?? ''));
    respond(['ok' => true]);
  }

  case 'setDayStatusRange': {
    $user = requireMember();
    $from = trim((string)($body['from'] ?? ''));
    $to = trim((string)($body['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
      fail('A valid start and end date are required');
    }
    $days = setDayStatusRange((int)$user['id'], $from, $to, (string)($body['dayType'] ?? 'Home'), (string)($body['notes'] ?? ''));
    respond(['ok' => true, 'days' => $days]);
  }

  // -- Phase 4: People --

  case 'people': {
    $user = requireMember();
    respond(['ok' => true, 'people' => listPeople((int)$user['id'])]);
  }

  case 'addPerson': {
    $user = requireMember();
    $id = addPerson((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updatePerson': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing person id'); }
    updatePerson((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  // -- Phase 4: Tags --

  case 'tags': {
    $user = requireMember();
    respond(['ok' => true, 'tags' => listTags((int)$user['id'])]);
  }

  case 'addTag': {
    $user = requireMember();
    $id = addTag((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateTag': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing tag id'); }
    updateTag((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  // -- Phase 4: Learning Projects --

  case 'learningProjects': {
    $user = requireMember();
    respond(['ok' => true, 'learningProjects' => listLearningProjects((int)$user['id'])]);
  }

  case 'addLearningProject': {
    $user = requireMember();
    $id = addLearningProject((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updateLearningProject': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing learning project id'); }
    updateLearningProject((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  case 'sharedLifeSummary': {
    $user = requireMember();
    respond(['ok' => true] + sharedLifeSummary((int)$user['id']));
  }

  // -- Phase 5: Engagement Scoring and Heat Maps --

  case 'engagementSummary': {
    $user = requireMember();
    respond(['ok' => true, 'summary' => engagementSummary((int)$user['id'])]);
  }

  case 'engagementHeatmap': {
    $user = requireMember();
    $weeks = isset($_GET['weeks']) ? (int)$_GET['weeks'] : 8;
    $weeks = max(4, min(26, $weeks));
    respond(['ok' => true, 'weeks' => engagementHeatmap((int)$user['id'], $weeks)]);
  }

  // -- Phase 6: Planned Events --

  case 'plannedEvents': {
    $user = requireMember();
    $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;
    respond(['ok' => true, 'events' => listPlannedEvents((int)$user['id'], $status)]);
  }

  case 'addPlannedEvent': {
    $user = requireMember();
    $id = addPlannedEvent((int)$user['id'], $body);
    respond(['ok' => true, 'id' => $id]);
  }

  case 'updatePlannedEvent': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing event id'); }
    updatePlannedEvent((int)$user['id'], $id, $body);
    respond(['ok' => true]);
  }

  case 'setPlannedEventStatus': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing event id'); }
    setPlannedEventStatus((int)$user['id'], $id, (string)($body['status'] ?? 'Planned'));
    respond(['ok' => true]);
  }

  case 'completePlannedEvent': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing event id'); }
    $result = completePlannedEvent((int)$user['id'], $id);
    respond(['ok' => true, 'logId' => $result['logId']]);
  }

  case 'deletePlannedEvent': {
    $user = requireMember();
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) { fail('Missing event id'); }
    deletePlannedEvent((int)$user['id'], $id);
    respond(['ok' => true]);
  }

  default:
    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 404);
}
