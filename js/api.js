// Fill this in after deploying api/api.php (see SETUP.md).
const CONFIG = {
  API_URL: "https://seniorfamily.org/life-tempo-api/api.php",
};

function isConfigured() {
  return CONFIG.API_URL && !CONFIG.API_URL.includes("YOUR_");
}

// ---- Login (My Apps Hub SSO token, or a manual fallback) ----
// Every private action checks this token against the shared sessions table
// plus an app_access grant for 'life-tempo'.

const TOKEN_KEY = 'ltToken';
function getToken() { return localStorage.getItem(TOKEN_KEY) || ''; }
function saveToken(t) { localStorage.setItem(TOKEN_KEY, t); }
function clearToken() { localStorage.removeItem(TOKEN_KEY); }
function authHeaders() {
  const t = getToken();
  return t ? { Authorization: `Bearer ${t}` } : {};
}

async function fetchData(action, params) {
  if (!isConfigured()) throw new Error('not-configured');
  const qs = new URLSearchParams({ action, ...(params || {}) });
  const res = await fetch(`${CONFIG.API_URL}?${qs.toString()}`, { headers: authHeaders() });
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

async function postAction(action, payload) {
  if (!isConfigured()) throw new Error('not-configured');
  const res = await fetch(CONFIG.API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain', ...authHeaders() },
    body: JSON.stringify({ action, ...(payload || {}) }),
  });
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str ?? '';
  return d.innerHTML;
}

// Renders a login-required / access-denied card into `container`. "no-token"
// includes a manual login form for a bookmarked visit that skipped the Hub's
// SSO handoff -- normally you never see it. "denied" means the login is fine
// but has no app_access grant for Life Tempo yet.
function renderGate(container, reason) {
  if (reason === 'denied') {
    container.innerHTML = `<div class="card"><p class="note">Your login isn't set up for Life Tempo yet. Ask to be granted the app in My Apps Hub, or open it again from there.</p></div>`;
    return;
  }
  container.innerHTML = `
    <div class="card">
      <h3>Log In</h3>
      <p class="note">Normally you won't see this — open Life Tempo from My Apps Hub and it logs you in automatically.</p>
      <label for="g-email">Email</label>
      <input type="email" id="g-email" autocomplete="username">
      <label for="g-pw">Password</label>
      <input type="password" id="g-pw" autocomplete="current-password">
      <p><button type="button" class="btn" id="g-btn">Log In</button></p>
      <div id="g-msg"></div>
    </div>`;
  const email = document.getElementById('g-email');
  const pw = document.getElementById('g-pw');
  const msg = document.getElementById('g-msg');
  const btn = document.getElementById('g-btn');
  async function attempt() {
    const username = email.value.trim();
    const password = pw.value;
    if (!username || !password) return;
    btn.disabled = true;
    msg.innerHTML = '';
    try {
      const res = await fetch(CONFIG.API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ action: 'login', username, password }),
      });
      const data = await res.json();
      if (!res.ok || !data.token) {
        msg.innerHTML = `<p class="note">${escapeHtml(data.error || 'Incorrect email or password.')}</p>`;
        btn.disabled = false;
        return;
      }
      saveToken(data.token);
      window.location.reload();
    } catch (e) {
      msg.innerHTML = `<p class="note">Something went wrong. Please try again.</p>`;
      btn.disabled = false;
    }
  }
  btn.addEventListener('click', attempt);
  pw.addEventListener('keydown', ev => { if (ev.key === 'Enter') attempt(); });
}

function handleError(container, err) {
  const m = err && err.message;
  if (m === 'not-authorized') renderGate(container, getToken() ? 'denied' : 'no-token');
  else if (m === 'not-configured') container.innerHTML = `<p class="note">App isn't configured yet.</p>`;
  else container.innerHTML = `<p class="note">Couldn't load right now — check your connection and refresh.</p>`;
}

// My Apps Hub launches this app with ?token=... -- adopt it as the login and
// strip it from the URL. Run-once.
let _ssoDone = null;
function captureSso() {
  if (_ssoDone) return _ssoDone;
  _ssoDone = (async () => {
    const token = new URLSearchParams(window.location.search).get('token');
    if (!token) return;
    window.history.replaceState({}, document.title, window.location.pathname);
    saveToken(token);
  })();
  return _ssoDone;
}

// Phase 1 proof-of-life: resolves the token to a UserID + confirms the
// app_access grant. Domain calls (Phase 2+) will follow the same
// fetchData()/postAction() shape used here.
async function ping() {
  return fetchData('ping');
}

// ---- Phase 2 shared helpers (Today / History / Manage) ----

function todayStr() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

// Local wall-clock "now" in the same 'YYYY-MM-DD HH:MM:SS' shape the API
// stores DATETIME columns in -- deliberately NOT toISOString(), which is
// UTC and would compare wrong against locally-entered planned-event times.
function nowDateTimeStr() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

// A MySQL DATETIME string ("2026-09-20 14:30:00") -> "14:30" for a <input
// type=time>, or '' if null/empty.
function timeOnly(dateTimeStr) {
  if (!dateTimeStr) return '';
  const m = String(dateTimeStr).match(/(\d{2}:\d{2})/);
  return m ? m[1] : '';
}

function formatTime12h(dateTimeStr) {
  const t = timeOnly(dateTimeStr);
  if (!t) return '';
  let [h, m] = t.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return `${h}:${String(m).padStart(2, '0')} ${ampm}`;
}

function formatDuration(minutes) {
  if (minutes === null || minutes === undefined || minutes === '') return '';
  const m = Number(minutes);
  if (!m) return '0 min';
  const h = Math.floor(m / 60);
  const rem = m % 60;
  if (h && rem) return `${h} hr ${rem} min`;
  if (h) return `${h} hr`;
  return `${rem} min`;
}

function formatDateHeading(dateStr) {
  const [y, mo, d] = dateStr.split('-').map(Number);
  const dt = new Date(y, mo - 1, d);
  const today = todayStr();
  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);
  const pad = n => String(n).padStart(2, '0');
  const yStr = `${yesterday.getFullYear()}-${pad(yesterday.getMonth() + 1)}-${pad(yesterday.getDate())}`;
  if (dateStr === today) return 'Today';
  if (dateStr === yStr) return 'Yesterday';
  return dt.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' });
}

function formatMoney(amount) {
  if (amount === null || amount === undefined || amount === '') return '';
  return '$' + Number(amount).toFixed(2);
}

// Renders one activity log entry as a .log-row element. `onEdit`/`onDelete`
// are called with the entry when their buttons are clicked.
function logRowEl(entry, onEdit, onDelete) {
  const row = document.createElement('div');
  row.className = 'log-row';

  // Each piece is escaped individually (not the joined string) because
  // the location piece is a real <a> link, not plain text.
  const timeBits = [];
  if (entry.StartTime) timeBits.push(escapeHtml(formatTime12h(entry.StartTime) + (entry.EndTime ? '–' + formatTime12h(entry.EndTime) : '')));
  if (entry.DurationMinutes !== null) timeBits.push(escapeHtml(formatDuration(entry.DurationMinutes)));
  if (entry.LocationName) timeBits.push(locationLinkHtml(entry.LocationName, entry.LocationAddress));
  if (entry.CostAmount !== null) timeBits.push(escapeHtml(formatMoney(entry.CostAmount)));
  if (entry.PersonNames && entry.PersonNames.length) timeBits.push('With ' + escapeHtml(entry.PersonNames.join(', ')));
  if (entry.LearningProjectName) timeBits.push(`${escapeHtml(entry.LearningMode || 'Learn')}: ${escapeHtml(entry.LearningProjectName)}`);

  const main = document.createElement('div');
  main.className = 'lr-main';
  main.innerHTML = `
    <div class="lr-activity">${escapeHtml(entry.ActivityName)}
      ${entry.Productive ? '<span class="badge productive">Productive</span>' : ''}
      ${entry.Billable ? '<span class="badge billable">Billable</span>' : ''}
      ${entry.SharedLife ? '<span class="badge tracked">Shared Life</span>' : ''}
    </div>
    <div class="lr-meta">${timeBits.join(' · ')}</div>
    ${entry.TagNames && entry.TagNames.length ? `<div class="lr-meta">${entry.TagNames.map(t => `<span class="badge inactive">${escapeHtml(t)}</span>`).join(' ')}</div>` : ''}
    ${entry.Notes ? `<div class="lr-notes">${escapeHtml(entry.Notes)}</div>` : ''}
  `;

  const actions = document.createElement('div');
  actions.className = 'lr-actions';
  const editBtn = document.createElement('button');
  editBtn.type = 'button';
  editBtn.className = 'btn secondary';
  editBtn.textContent = 'Edit';
  editBtn.addEventListener('click', () => onEdit(entry));
  const delBtn = document.createElement('button');
  delBtn.type = 'button';
  delBtn.className = 'btn secondary';
  delBtn.textContent = 'Delete';
  delBtn.addEventListener('click', () => onDelete(entry));
  actions.appendChild(editBtn);
  actions.appendChild(delBtn);

  row.appendChild(main);
  row.appendChild(actions);
  return row;
}

// Builds a <select multiple> for id-based multi-select fields (goal/log
// activities, log participants, log tags). `selectedIds` may hold numbers
// or strings -- compared as strings so either works.
function multiSelectHtml(id, items, valueKey, labelKey, selectedIds) {
  const selectedSet = new Set((selectedIds || []).map(String));
  const opts = items.map(i => `<option value="${i[valueKey]}" ${selectedSet.has(String(i[valueKey])) ? 'selected' : ''}>${escapeHtml(i[labelKey])}</option>`).join('');
  return `<select id="${id}" multiple size="${Math.min(5, Math.max(3, items.length || 1))}">${opts}</select>`;
}

// ---- Phase 6 shared helpers (navigation + calendar links) ----

// A Google Maps search link for a location -- prefers the street address
// (more precise) and falls back to the location name (spec section 22:
// "Navigate" action).
function mapsUrl(name, address) {
  const q = (address && address.trim()) || name || '';
  if (!q) return null;
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;
}

function locationLinkHtml(name, address) {
  const url = mapsUrl(name, address);
  if (!url || !name) return name ? escapeHtml(name) : '';
  return `<a href="${url}" target="_blank" rel="noopener">${escapeHtml(name)} ↗</a>`;
}

// A "quick add" Google Calendar link for a planned event -- no full
// synchronization, just a one-click way to also see it on an external
// calendar (spec section 21/66).
function gcalUrl(event) {
  const start = new Date(event.StartDateTime.replace(' ', 'T'));
  const end = event.EndDateTime
    ? new Date(event.EndDateTime.replace(' ', 'T'))
    : new Date(start.getTime() + 60 * 60 * 1000);
  const pad = n => String(n).padStart(2, '0');
  const fmt = d => `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}T${pad(d.getHours())}${pad(d.getMinutes())}00`;
  const params = new URLSearchParams({
    action: 'TEMPLATE',
    text: event.Title,
    dates: `${fmt(start)}/${fmt(end)}`,
  });
  if (event.LocationAddress || event.LocationName) params.set('location', event.LocationAddress || event.LocationName);
  if (event.Notes) params.set('details', event.Notes);
  return `https://calendar.google.com/calendar/render?${params.toString()}`;
}

function selectedValues(selectEl) {
  return Array.from(selectEl.selectedOptions).map(o => o.value);
}

// ---- Phase 7 shared helper (CSV export) ----
// Client-side only -- every export is built from data a GET action already
// returns, so there's no server-side export endpoint to maintain.

function toCsv(columns, rows) {
  const esc = v => {
    const s = v === null || v === undefined ? '' : String(v);
    return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  };
  const header = columns.map(c => esc(c.label)).join(',');
  const body = rows.map(row => columns.map(c => esc(typeof c.value === 'function' ? c.value(row) : row[c.value])).join(',')).join('\n');
  return header + '\n' + body;
}

function downloadCsv(filename, columns, rows) {
  const csv = toCsv(columns, rows);
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

// ---- Phase 3 shared helpers (Dashboard / Today's day-status) ----

function goalStatusClass(status) {
  return String(status).toLowerCase().replace(/[^a-z]+/g, '-').replace(/(^-|-$)/g, '');
}

function goalCardEl(p) {
  const card = document.createElement('div');
  card.className = 'goal-card';
  const cadenceLabel = p.CadenceType + (p.CadenceType !== 'Monthly' ? ' (this week)' : ' (this month)');
  const expected = Number(p.Expected);
  const actual = Number(p.Actual);
  const barPercent = Math.max(0, Math.min(100, p.Percent));
  const overBar = p.GoalType === 'Maximum' && actual > expected;
  card.innerHTML = `
    <div class="gc-head">
      <span class="gc-name">${escapeHtml(p.Name)}</span>
      <span class="badge ${goalStatusClass(p.Status)}">${escapeHtml(p.Status)}</span>
    </div>
    <div class="gc-meta">
      ${cadenceLabel} · ${p.GoalType} · ${actual} of ${expected}
      ${!p.HasActivities ? ' · <em>no activities linked yet</em>' : ''}
    </div>
    <div class="progress-track"><div class="progress-fill ${overBar ? 'over' : ''}" style="width:${barPercent}%"></div></div>
  `;
  return card;
}

// Groups activity log entries (already sorted newest-date-first by the API)
// into a list of { date, entries } for rendering under day headings.
function groupByDate(entries) {
  const groups = [];
  let current = null;
  for (const e of entries) {
    if (!current || current.date !== e.ActivityDate) {
      current = { date: e.ActivityDate, entries: [] };
      groups.push(current);
    }
    current.entries.push(e);
  }
  return groups;
}
