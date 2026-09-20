# Setup Guide

Life Tempo is backed by **MyDataWorld** — the same shared database and single
sign-on as My Apps Hub. Every request needs a MyDataWorld login with an
`app_access` grant for `life-tempo`.

If you already completed Phase 1 setup, jump to **[Phase 3 update](#phase-3-update)** below — steps 1-6 here are the original Phase 1 walkthrough and don't need repeating.

## 1. Register the app with My Apps Hub

In **phpMyAdmin**, select the MyDataWorld database, open the **SQL** tab, and
run the "NEW APP: Life Tempo" block appended to **My Apps Hub's** own
`api/schema.sql` (it adds the `life-tempo` row to the `apps` table). Safe to
re-run — it's `ON DUPLICATE KEY UPDATE`.

## 2. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real
   `DB_NAME`, `DB_USER`, `DB_PASS` (same credentials as your other MyDataWorld
   apps).
2. Upload the whole `api/` folder via FTP / File Manager to
   `seniorfamily.org/life-tempo-api/` (so the endpoint is
   `https://seniorfamily.org/life-tempo-api/api.php`).

## 3. Point the site at your API

`js/api.js` already has:

```js
const CONFIG = {
  API_URL: "https://seniorfamily.org/life-tempo-api/api.php",
};
```

Change it only if you upload the API somewhere else.

## 4. Publish

Push this folder to the GitHub repo `dsshrek-ai/life-tempo`, then
Settings → Pages → Deploy from a branch → `main` / `/ (root)`. The app goes
live at `https://dsshrek-ai.github.io/life-tempo/`.

## 5. Grant yourself access

1. Sign up through **My Apps Hub** with your email if you haven't already.
2. In the Hub's `admin.html`, grant your account **Life Tempo**, or run:

   ```sql
   INSERT INTO app_access (user_id, app_id)
   SELECT u.id, a.id FROM users u JOIN apps a ON a.app_key = 'life-tempo'
   WHERE u.username = 'you@example.com'
   ON DUPLICATE KEY UPDATE user_id = user_id;
   ```

## 6. Verify Phase 1

Open the app from My Apps Hub. You should land on "You're in." with your
display name and UserID — that confirms the SSO handoff, session lookup, and
`app_access` check are all wired correctly. If you instead see a login form or
an access-denied note, re-check steps 1 and 5.

## Single sign-on

The app is registered with `sso_enabled = 1`, so launching it from My Apps Hub
skips the login screen (`?token=...` handoff). `js/api.js` captures the token,
saves it as the Bearer credential, and strips it from the URL.

## Usage logging

Every successful action upserts a row into the shared `app_usage_log`
(`app_key = 'life-tempo'`), one row per day.

## Phase 2 update

Phase 2 adds Categories, Locations, Activities, and the Activity Log itself —
the Today/History/Manage screens.

1. In phpMyAdmin, run `api/schema.sql` again. It's all `CREATE TABLE IF NOT
   EXISTS`, so it only adds the four new tables (`lt_categories`,
   `lt_locations`, `lt_activities`, `lt_activity_log`) and won't touch
   anything Phase 1 already created.
2. Re-upload `api/api.php` (unchanged `config.php`) to
   `seniorfamily.org/life-tempo-api/` — it now has the Category/Location/
   Activity/ActivityLog actions alongside the Phase 1 auth actions.
3. Push/pull the updated front end (`index.html`, `history.html`,
   `manage.html`, `js/api.js`, `style.css`) to GitHub Pages as usual.
4. Open the app, go to **Manage**, and add at least one Category and
   Activity — Today and History have nothing to show until an Activity
   exists.

## Phase 3 update

Phase 3 adds Goals, a weekly/monthly Dashboard, and a day-type control on
Today.

1. In phpMyAdmin, run `api/schema.sql` again — it only adds `lt_goals`,
   `lt_goal_activity`, and `lt_day_status` (`CREATE TABLE IF NOT EXISTS`,
   same as before).
2. Re-upload `api/api.php` to `seniorfamily.org/life-tempo-api/` — it now
   has the Goal/DayStatus actions alongside Phases 1-2.
3. Push/pull the updated front end (`index.html`, `dashboard.html` is new,
   `manage.html`, `js/api.js`, `style.css`).
4. On **Manage**, add a goal (e.g. "Exercise" / Target / Weekly / 5) and
   link it to the activities that should count toward it. Check
   **Dashboard** for its progress.
5. On **Today**, the "Today is a:" selector defaults to Home — only change
   it on days that shouldn't count toward Daily-cadence goals (Travel,
   Vacation, Sick, ...).

## Phase 4 update

Phase 4 adds People, Tags, Learning Projects, a Shared Life flag, and
Learn/Apply tracking on the log form.

1. In phpMyAdmin, run `api/schema.sql` again. It adds four new tables
   (`lt_people`, `lt_tags`, `lt_learning_projects`, `lt_activity_log_person`,
   `lt_activity_log_tag` — five, actually) plus two plain `ALTER TABLE`
   statements on `lt_activity_log` (`shared_life`, `learning_project_id`,
   `learning_mode` columns + their indexes/FK). **The ALTER statements are
   not idempotent** — if you already ran this Phase 4 block once, skip
   re-running just those two `ALTER TABLE` statements (the `CREATE TABLE IF
   NOT EXISTS` ones are still safe to re-run).
2. Re-upload `api/api.php`.
3. Push/pull the updated front end.
4. On **Manage**, add a few People and Tags, and a Learning Project if
   you're tracking one. Then on **Today/History**, a log entry can name who
   was with you, mark itself Shared Life, carry tags, and optionally link a
   Learning Project with a Learn/Apply mode.
5. Check **Dashboard** for the new Shared Life count.

## Phase 5 update

Phase 5 adds goal weighting and the Engagement page (overall score +
weekly heat map).

1. In phpMyAdmin, run `api/schema.sql` again. It adds one plain `ALTER
   TABLE lt_goals ADD COLUMN weight ...` — **not idempotent**, skip it if
   you already ran this Phase 5 block once.
2. Re-upload `api/api.php`.
3. Push/pull the updated front end (`engagement.html` is new).
4. On **Manage → Goals**, every goal now has a Weight column (default 1) —
   raise it for goals that matter more, lower it for goals that matter less
   to the overall score.
5. Check **Engagement** for the This Week/Rolling 4 Weeks/This Month/This
   Quarter/This Year scores, each with a Breakdown, and the weekly heat map.
   Scores need at least one active goal (not TrackOnly) linked to at least
   one activity to show anything other than "No data."

## Phase 6 update

Phase 6 adds Seasons, Planned Events, and a bulk date-range Day Status.

1. In phpMyAdmin, run `api/schema.sql` again. It adds `lt_seasons`,
   `lt_goal_season`, and `lt_planned_events` (all `CREATE TABLE IF NOT
   EXISTS`, safe to re-run), plus one plain `ALTER TABLE lt_activity_log
   ADD COLUMN planned_event_id ...` — **not idempotent**, skip it if you
   already ran this Phase 6 block once.
2. Re-upload `api/api.php`.
3. Push/pull the updated front end (`plan.html` is new).
4. On **Manage → Seasons**, add a season (e.g. "Concert Band Season", `01-01`
   to `09-30`) and, on a goal, select it under Seasons to make that goal
   only count while in season.
5. On **Plan**, add an upcoming event. When it happens, click **Complete**
   — if the event has an Activity, this also creates the ActivityLog entry
   for it automatically. Use **Mark a Date Range** for a multi-day trip or
   illness instead of setting each day individually on Today.
6. Locations now show a **Navigate** link (Manage, and anywhere a location
   appears on Today/History/Plan) that opens Google Maps.

## Phase 7 update

Phase 7 adds Trips, Clients, billable reporting, and CSV export.

1. In phpMyAdmin, run `api/schema.sql` again. It adds `lt_trips`,
   `lt_trip_stops`, `lt_trip_expenses`, and `lt_clients` (all `CREATE TABLE
   IF NOT EXISTS`, safe to re-run), plus one plain `ALTER TABLE
   lt_activity_log ADD COLUMN client_id ...` — **not idempotent**, skip it
   if you already ran this Phase 7 block once.
2. Re-upload `api/api.php`.
3. Push/pull the updated front end (`trips.html` and `reports.html` are
   new).
4. On **Trips**, plan a trip and add its stops/expenses as they firm up or
   as you spend money on it.
5. On **Manage → Clients**, add a client if you do billable work, then
   attribute billable log entries to it on Today/History.
6. Check **Reports** for the Billable Report (by client, by date range) and
   CSV export for it and for Trips. **History** also got its own **Download
   CSV** button for the currently filtered activity log.

## Feature toggles update

Adds `lt_user_preferences` and Manage's Settings section for turning Travel
or Billable/Invoice off per user.

1. In phpMyAdmin, run `api/schema.sql` again — it only adds
   `lt_user_preferences` (`CREATE TABLE IF NOT EXISTS`, safe to re-run).
2. Re-upload `api/api.php`.
3. Push/pull the updated front end (no new pages, all 8 existing ones got a
   small preferences check added).
4. On **Manage → Settings**, turn off Travel and/or Billable/Invoice for any
   login that doesn't need them. Nothing already entered is deleted, and
   the hidden nav links/fields come right back if turned on again.
