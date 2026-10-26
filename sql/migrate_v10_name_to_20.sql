-- ============================================================
-- CraftHub Organizer — Migration v10
-- Set name column to VARCHAR(20) to match name field limits
-- ============================================================
--
-- users.name is the auto-built display name (e.g. "Maria Santos").
-- VARCHAR(20) is consistent with the individual name part limits
-- (first_name 20 + surname 20 — the display name uses a shorter
-- combined form like "Maria Santos").
--
-- NOTE: email stays at VARCHAR(100). The longest existing email
-- in the database is 28 chars, which already exceeds 20 — setting
-- email to VARCHAR(20) would corrupt existing user records.
-- ============================================================

USE crafthub;

ALTER TABLE users
    MODIFY COLUMN name VARCHAR(20) NOT NULL COMMENT 'Auto-built display name; max 20 chars';

SELECT 'Migration v10 completed: name column resized to VARCHAR(20).' AS status;
