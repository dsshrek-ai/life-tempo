-- Task lists + the Wall: just the new tables (also at the end of schema.sql).
-- Safe to run more than once.

-- ---------- TASK LISTS + THE WALL (2026-11-25) ----------
-- Run once. Life Tempo's own task lists (separate from Master Checklist's).
-- Tasks nest to grandchildren (depth 0-2). A list is shared with other Life
-- Tempo users by email; everyone on it can add, edit and check tasks, while
-- renaming, sharing and deleting the list stay with the owner. `version` is
-- bumped on every change so open pages can pick up each other's edits.
CREATE TABLE IF NOT EXISTS lt_task_lists (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,                  -- owner
  name        VARCHAR(255) NOT NULL,
  version     INT NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_lt_task_lists_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_task_list_shares (
  list_id     INT NOT NULL,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (list_id, user_id),
  KEY ix_lt_task_list_shares_user (user_id),
  FOREIGN KEY (list_id) REFERENCES lt_task_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_tasks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  list_id       INT NOT NULL,
  created_by    INT NOT NULL,
  parent_id     INT NULL,
  depth         TINYINT NOT NULL DEFAULT 0,  -- 0 task, 1 child, 2 grandchild
  sort_order    INT NOT NULL DEFAULT 0,      -- among siblings
  what          VARCHAR(500) NOT NULL,
  where_text    VARCHAR(255) NULL,
  when_date     DATE NULL,
  when_time     TIME NULL,
  who           VARCHAR(255) NULL,
  notes         TEXT NULL,
  complete      TINYINT(1) NOT NULL DEFAULT 0,
  completed_by  INT NULL,
  completed_at  TIMESTAMP NULL DEFAULT NULL,
  cleared_at    TIMESTAMP NULL DEFAULT NULL, -- "Clear checked" hides; Restore brings back
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_lt_tasks_list (list_id, parent_id, sort_order),
  FOREIGN KEY (list_id)    REFERENCES lt_task_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id)  REFERENCES lt_tasks(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
