# Setup Guide — Phase 1

Life Tempo is backed by **MyDataWorld** — the same shared database and single
sign-on as My Apps Hub. Every request needs a MyDataWorld login with an
`app_access` grant for `life-tempo`. Phase 1 adds no domain tables of its own
yet (see `docs/life-tempo-spec.md` section 146) — this just wires up login and
a tenant-safe API shell.

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
