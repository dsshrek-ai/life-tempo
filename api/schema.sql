-- ============================================================
-- Life Tempo -- schema for MyDataWorld
-- Run in phpMyAdmin's SQL tab against the MyDataWorld database, AFTER My
-- Apps Hub's own api/schema.sql (this uses the shared users/sessions/apps/
-- app_access/app_usage_log tables).
--
-- Own tables are prefixed lt_ so they can't collide with another app's
-- (same convention as Choir Connect's cc_* and Reading List's reading_*).
-- Column names are snake_case to match the shared platform tables and
-- every other MyDataWorld app -- docs/life-tempo-spec.md's Section 92
-- suggests PascalCase, but the actual codebase convention wins.
--
-- Tenant-safety convention every table follows (spec section 92-94): every
-- user-owned table gets a user_id column, every read/write is scoped by
-- BOTH the record's own id AND the authenticated user_id -- never by id
-- alone. Master tables (categories/activities/locations) are soft-deactivated
-- via `active` rather than deleted (spec section 87/96), so history in
-- lt_activity_log stays meaningful even after an activity is retired.
-- ============================================================

-- ---------- PLATFORM TABLES (shared) -- idempotent for a standalone run ----------

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NULL,
  display_name   VARCHAR(100) NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- PHASE 1: no domain tables (foundation/auth only) ----------
-- Login, sessions, and app_access are all handled by the platform tables
-- above -- see docs/life-tempo-spec.md section 146 for the phase roadmap.

-- ---------- PHASE 2: Core Activity Logging ----------
-- Category / Activity / Location / ActivityLog, trimmed to what Phase 2
-- actually needs (spec section 147.1 -- don't build every V3 column until a
-- phase requires it). Engagement-scoring fields, calendar links, trip/
-- learning/work-project links, etc. arrive with the phases that use them.

CREATE TABLE IF NOT EXISTS lt_categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(500) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_categories_user_name (user_id, name),
  KEY ix_lt_categories_user (user_id, active, sort_order),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_locations (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  name          VARCHAR(150) NOT NULL,
  location_type VARCHAR(50) NULL,
  address       VARCHAR(300) NULL,
  city          VARCHAR(100) NULL,
  state_region  VARCHAR(100) NULL,
  postal_code   VARCHAR(20) NULL,
  phone         VARCHAR(50) NULL,
  website_url   VARCHAR(500) NULL,
  notes         VARCHAR(1000) NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_lt_locations_user (user_id, active, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_activities (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  user_id                   INT NOT NULL,
  name                      VARCHAR(150) NOT NULL,
  category_id               INT NULL,
  description               VARCHAR(1000) NULL,
  typical_duration_minutes  INT UNSIGNED NULL,
  productive                TINYINT(1) NOT NULL DEFAULT 0,
  billable_eligible         TINYINT(1) NOT NULL DEFAULT 0,
  default_location_id       INT NULL,
  quick_log                 TINYINT(1) NOT NULL DEFAULT 0,
  active                    TINYINT(1) NOT NULL DEFAULT 1,
  created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_activities_user_name (user_id, name),
  KEY ix_lt_activities_user (user_id, active),
  KEY ix_lt_activities_quicklog (user_id, quick_log, active),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES lt_categories(id) ON DELETE SET NULL,
  FOREIGN KEY (default_location_id) REFERENCES lt_locations(id) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_activity_log (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  activity_id       INT NOT NULL,
  activity_date     DATE NOT NULL,
  start_time        DATETIME NULL,
  end_time          DATETIME NULL,
  duration_minutes  INT UNSIGNED NULL,
  location_id       INT NULL,
  productive        TINYINT(1) NOT NULL DEFAULT 0,
  billable          TINYINT(1) NOT NULL DEFAULT 0,
  cost_amount       DECIMAL(12,2) NULL,
  notes             VARCHAR(2000) NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_lt_activity_log_user_date (user_id, activity_date),
  KEY ix_lt_activity_log_user_activity (user_id, activity_id, activity_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (activity_id) REFERENCES lt_activities(id),
  FOREIGN KEY (location_id) REFERENCES lt_locations(id) ON DELETE SET NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BOOTSTRAP (run once, after you've signed up through My Apps Hub):
--
-- 1) Register the app with the Hub -- run the "NEW APP: Life Tempo" block
--    appended to my-apps-hub/api/schema.sql.
--
-- 2) Grant yourself the app (or use the Hub's admin.html):
--      INSERT INTO app_access (user_id, app_id)
--      SELECT u.id, a.id FROM users u, apps a
--      WHERE u.username = 'you@example.com' AND a.app_key = 'life-tempo';
--
-- 3) Open Manage in the app and add at least one Category and Activity
--    before Today/History have anything to show.
-- ============================================================
