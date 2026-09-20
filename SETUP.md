# Setup Guide

Life Tempo is backed by **MyDataWorld** — the same shared database and single
sign-on as My Apps Hub. Every request needs a MyDataWorld login with an
`app_access` grant for `life-tempo`.

If you already completed Phase 1 setup, jump to **[Phase 2 update](#phase-2-update)** below — steps 1-6 here are the original Phase 1 walkthrough and don't need repeating.

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
