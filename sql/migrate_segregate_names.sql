-- ============================================================
-- CraftHub Organizer — Migration: Segregate Names in Users Table
-- File: sql/migrate_segregate_names.sql (v6)
--
-- This script ensures first_name, middle_name, last_name, and
-- surname exist as separate columns in the `users` table, and
-- automatically splits/backfills existing full names.
-- ============================================================

USE crafthub;

-- ------------------------------------------------------------
-- 1. Add segregated name columns if they do not exist
-- ------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(20) NULL AFTER name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS middle_name VARCHAR(15) NULL AFTER first_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(20) NULL AFTER middle_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS surname VARCHAR(20) NULL AFTER last_name;

-- ------------------------------------------------------------
-- 2. Sync existing surname <-> last_name columns
-- ------------------------------------------------------------
UPDATE users 
SET last_name = surname 
WHERE (last_name IS NULL OR last_name = '') AND (surname IS NOT NULL AND surname != '');

UPDATE users 
SET surname = last_name 
WHERE (surname IS NULL OR surname = '') AND (last_name IS NOT NULL AND last_name != '');

-- ------------------------------------------------------------
-- 3. Automatically segregate full `name` into parts for users
--    where first_name or last_name/surname are still empty
-- ------------------------------------------------------------

-- Case A: 2-word names (e.g., 'Admin User' -> 'Admin', '', 'User')
UPDATE users
SET 
    first_name  = SUBSTRING_INDEX(TRIM(name), ' ', 1),
    middle_name = '',
    last_name   = SUBSTRING(TRIM(name), LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', 1)) + 2),
    surname     = SUBSTRING(TRIM(name), LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', 1)) + 2)
WHERE (first_name IS NULL OR first_name = '')
  AND (last_name IS NULL OR last_name = '')
  AND name LIKE '% %'
  AND (LENGTH(TRIM(name)) - LENGTH(REPLACE(TRIM(name), ' ', ''))) = 1;

-- Case B: 3-word names (e.g., 'John Michael Doe' -> 'John', 'Michael', 'Doe')
UPDATE users
SET 
    first_name  = SUBSTRING_INDEX(TRIM(name), ' ', 1),
    middle_name = SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(name), ' ', 2), ' ', -1),
    last_name   = SUBSTRING_INDEX(TRIM(name), ' ', -1),
    surname     = SUBSTRING_INDEX(TRIM(name), ' ', -1)
WHERE (first_name IS NULL OR first_name = '')
  AND (last_name IS NULL OR last_name = '')
  AND (LENGTH(TRIM(name)) - LENGTH(REPLACE(TRIM(name), ' ', ''))) = 2;

-- Case C: 4+ word names (e.g., 'Mary Grace Ann Cadenas' -> 'Mary Grace', 'Ann', 'Cadenas')
UPDATE users
SET 
    first_name  = TRIM(SUBSTRING(TRIM(name), 1, LENGTH(TRIM(name)) - LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', -2)))),
    middle_name = SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(name), ' ', -2), ' ', 1),
    last_name   = SUBSTRING_INDEX(TRIM(name), ' ', -1),
    surname     = SUBSTRING_INDEX(TRIM(name), ' ', -1)
WHERE (first_name IS NULL OR first_name = '')
  AND (last_name IS NULL OR last_name = '')
  AND (LENGTH(TRIM(name)) - LENGTH(REPLACE(TRIM(name), ' ', ''))) > 2;

-- Case D: Single-word names (e.g., 'Admin' -> 'Admin', '', 'Admin')
UPDATE users
SET 
    first_name  = TRIM(name),
    middle_name = '',
    last_name   = TRIM(name),
    surname     = TRIM(name)
WHERE (first_name IS NULL OR first_name = '')
  AND (last_name IS NULL OR last_name = '')
  AND name NOT LIKE '% %'
  AND name IS NOT NULL AND TRIM(name) != '';

-- ------------------------------------------------------------
-- 4. View results
-- ------------------------------------------------------------
SELECT user_id, name, first_name, middle_name, last_name, surname, role 
FROM users 
ORDER BY user_id ASC;
