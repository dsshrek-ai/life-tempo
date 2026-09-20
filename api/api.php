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
                 l.name AS location_name, al.productive, al.billable, al.cost_amount, al.notes
          FROM lt_activity_log al
          JOIN lt_activities a ON a.id = al.activity_id
          LEFT JOIN lt_locations l ON l.id = al.location_id
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
      'Productive' => (bool)$r['productive'],
      'Billable' => (bool)$r['billable'],
      'CostAmount' => $r['cost_amount'] !== null ? (float)$r['cost_amount'] : null,
      'Notes' => (string)($r['notes'] ?? ''),
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
  $costAmount = (isset($b['costAmount']) && $b['costAmount'] !== '') ? (float)$b['costAmount'] : null;
  $notes = nullIfEmpty((string)($b['notes'] ?? ''));
  return [$activityId, $date, $startTime, $endTime, $duration, $locationId, $productive, $billable, $costAmount, $notes];
}

function addActivityLog(int $userId, array $b): int {
  [$activityId, $date, $startTime, $endTime, $duration, $locationId, $productive, $billable, $costAmount, $notes] = activityLogFields($userId, $b);
  if ($activityId === null) { fail('A valid activity is required'); }
  $stmt = db()->prepare(
    'INSERT INTO lt_activity_log
      (user_id, activity_id, activity_date, start_time, end_time, duration_minutes, location_id, productive, billable, cost_amount, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
  );
  $stmt->bind_param('iisssiiiids', $userId, $activityId, $date, $startTime, $endTime, $duration, $locationId, $productive, $billable, $costAmount, $notes);
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return $id;
}

function updateActivityLog(int $userId, int $id, array $b): void {
  [$activityId, $date, $startTime, $endTime, $duration, $locationId, $productive, $billable, $costAmount, $notes] = activityLogFields($userId, $b);
  if ($activityId === null) { fail('A valid activity is required'); }
  $stmt = db()->prepare(
    'UPDATE lt_activity_log
     SET activity_id = ?, activity_date = ?, start_time = ?, end_time = ?, duration_minutes = ?,
         location_id = ?, productive = ?, billable = ?, cost_amount = ?, notes = ?
     WHERE id = ? AND user_id = ?'
  );
  $stmt->bind_param('isssiiiidsii', $activityId, $date, $startTime, $endTime, $duration, $locationId, $productive, $billable, $costAmount, $notes, $id, $userId);
  $stmt->execute();
  $stmt->close();
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

  default:
    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 404);
}
