-- Task contacts (2026-11-26): a contact name and phone on each task, for tap
-- to call or text.
--
-- Run this ONCE, and only if lt_tasks already exists (you ran
-- tasks-schema.sql before 2026-11-26). A fresh tasks-schema.sql already has
-- these columns -- running this after it gives "Duplicate column name",
-- which is harmless.
ALTER TABLE lt_tasks
  ADD COLUMN contact_name  VARCHAR(255) NULL AFTER notes,
  ADD COLUMN contact_phone VARCHAR(40)  NULL AFTER contact_name;
