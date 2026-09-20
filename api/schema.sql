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

-- ---------- PHASE 3: Goals, Cadence, and Weekly Progress ----------
-- Goal / GoalActivity / DayStatus, trimmed the same way Phase 2 was: no
-- weight or unit_type columns yet (those are Phase 5 engagement-scoring
-- concerns) -- every initial retirement rhythm in spec section 39 is a
-- plain per-period count, so goal progress is computed as a count of
-- qualifying lt_activity_log rows against target_value.

CREATE TABLE IF NOT EXISTS lt_goals (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  name          VARCHAR(150) NOT NULL,
  goal_type     VARCHAR(20) NOT NULL,   -- Minimum | Target | Maximum | TrackOnly
  cadence_type  VARCHAR(20) NOT NULL,   -- Daily | Weekly | Monthly
  target_value  DECIMAL(10,2) NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_goals_user_name (user_id, name),
  KEY ix_lt_goals_user (user_id, active),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_goal_activity (
  goal_id      INT NOT NULL,
  activity_id  INT NOT NULL,
  PRIMARY KEY (goal_id, activity_id),
  KEY ix_lt_goal_activity_activity (activity_id, goal_id),
  FOREIGN KEY (goal_id) REFERENCES lt_goals(id) ON DELETE CASCADE,
  FOREIGN KEY (activity_id) REFERENCES lt_activities(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Whether a day counts toward daily-cadence goal expectations (spec section
-- 13/62). No row for a date means "applicable" -- you only add a row to
-- mark an exception (Travel/Vacation/Sick/...).
CREATE TABLE IF NOT EXISTS lt_day_status (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  user_id                   INT NOT NULL,
  calendar_date             DATE NOT NULL,
  day_type                  VARCHAR(20) NOT NULL,  -- Home | Local Outing | Travel | Vacation | Sick | Special Event
  productive_goal_applies   TINYINT(1) NOT NULL DEFAULT 1,
  notes                     VARCHAR(500) NULL,
  created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_day_status_user_date (user_id, calendar_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- PHASE 4: People, Shared Life, and Learning ----------
-- Trimmed from the V3 spec's richer model (sections 59-61, 71-73):
--
-- - Shared Life becomes a single flag on the log entry itself (like
--   Productive/Billable already are) rather than a per-person
--   ActivityParticipant.SharedLifeFlag -- nothing yet needs per-person
--   granularity, and this is much simpler to log against.
-- - LearningActivityLink's 1:1 relationship (spec section 117: "One
--   ActivityLog should normally describe one learning context") becomes
--   two nullable columns directly on lt_activity_log instead of a
--   separate joined table.
-- - Tags apply per log entry only (lt_activity_log_tag) -- default
--   per-Activity tags (spec's ActivityTag) are skipped for now; typing
--   a tag occasionally is cheap enough that pre-filling isn't essential yet.

CREATE TABLE IF NOT EXISTS lt_people (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  display_name      VARCHAR(150) NOT NULL,
  relationship_type VARCHAR(50) NULL,
  active            TINYINT(1) NOT NULL DEFAULT 1,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_lt_people_user (user_id, active, display_name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_tags (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  name        VARCHAR(100) NOT NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_tags_user_name (user_id, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_learning_projects (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  name        VARCHAR(150) NOT NULL,
  description VARCHAR(1000) NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_learning_projects_user_name (user_id, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_activity_log_person (
  activity_log_id  INT NOT NULL,
  person_id        INT NOT NULL,
  PRIMARY KEY (activity_log_id, person_id),
  KEY ix_lt_activity_log_person_person (person_id, activity_log_id),
  FOREIGN KEY (activity_log_id) REFERENCES lt_activity_log(id) ON DELETE CASCADE,
  FOREIGN KEY (person_id) REFERENCES lt_people(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_activity_log_tag (
  activity_log_id  INT NOT NULL,
  tag_id           INT NOT NULL,
  PRIMARY KEY (activity_log_id, tag_id),
  KEY ix_lt_activity_log_tag_tag (tag_id, activity_log_id),
  FOREIGN KEY (activity_log_id) REFERENCES lt_activity_log(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES lt_tags(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SCHEMA CHANGE: shared life + learning columns on ActivityLog ----------
-- Run once (plain ALTER, not IF NOT EXISTS -- matches the convention already
-- used in my-apps-hub's own schema.sql for evolutionary changes).

ALTER TABLE lt_activity_log
  ADD COLUMN shared_life TINYINT(1) NOT NULL DEFAULT 0 AFTER billable,
  ADD COLUMN learning_project_id INT NULL AFTER notes,
  ADD COLUMN learning_mode VARCHAR(10) NULL AFTER learning_project_id;

ALTER TABLE lt_activity_log
  ADD KEY ix_lt_activity_log_shared_life (user_id, shared_life, activity_date),
  ADD KEY ix_lt_activity_log_learning (user_id, learning_project_id, activity_date),
  ADD FOREIGN KEY (learning_project_id) REFERENCES lt_learning_projects(id) ON DELETE SET NULL;

-- ---------- PHASE 5: Engagement Scoring and Heat Maps ----------
-- Goal weighting (spec section 9/52) is the only schema change -- everything
-- else (engagement percent, the week/rolling-4/month/quarter/year summary,
-- and the heat map) is calculated live from lt_goals + lt_activity_log, not
-- persisted (spec section 129 explicitly says skip EngagementSnapshot for
-- the MVP unless live calculation proves too slow -- it won't, at this
-- table size). Run once.

ALTER TABLE lt_goals
  ADD COLUMN weight DECIMAL(6,3) NOT NULL DEFAULT 1.000 AFTER target_value;

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
--
-- 4) On Manage's Goals section, add a goal and link it to the activities
--    that should count toward it, then check Dashboard for its progress.
--
-- 5) On Manage's People/Tags/Learning Projects sections, add a few, then
--    use them from the Today/History log form (participants, shared life,
--    tags, learning project + mode).
--
-- 6) Check Engagement for the overall score (Week/Rolling 4 Weeks/Month/
--    Quarter/Year) and the weekly heat map. Set a goal's Weight above 1 to
--    make it count more toward the overall score, below 1 to count less.
-- ============================================================
