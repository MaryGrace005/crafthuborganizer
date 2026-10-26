-- ============================================================
-- CraftHub Organizer — Migration v6
-- Segregate Names in Users Table (first_name, middle_name, last_name, surname)
-- ============================================================

USE crafthub;

-- 1. Ensure segregated columns exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(50) NULL AFTER name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS middle_name VARCHAR(50) NULL AFTER first_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(50) NULL AFTER middle_name;
ALTER TABLE users ADD COLUMN IF NOT EXISTS surname VARCHAR(50) NULL AFTER last_name;

-- 2. Synchronize existing last_name <-> surname
UPDATE users SET last_name = surname WHERE (last_name IS NULL OR last_name = '') AND (surname IS NOT NULL AND surname != '');
UPDATE users SET surname = last_name WHERE (surname IS NULL OR surname = '') AND (last_name IS NOT NULL AND last_name != '');

-- 3. Populate empty segregated names from `name`
-- 2 words
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

-- 3 words
UPDATE users
SET 
    first_name  = SUBSTRING_INDEX(TRIM(name), ' ', 1),
    middle_name = SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(name), ' ', 2), ' ', -1),
    last_name   = SUBSTRING_INDEX(TRIM(name), ' ', -1),
    surname     = SUBSTRING_INDEX(TRIM(name), ' ', -1)
WHERE (first_name IS NULL OR first_name = '')
  AND (last_name IS NULL OR last_name = '')
  AND (LENGTH(TRIM(name)) - LENGTH(REPLACE(TRIM(name), ' ', ''))) = 2;

-- 4+ words
UPDATE users
SET 
    first_name  = TRIM(SUBSTRING(TRIM(name), 1, LENGTH(TRIM(name)) - LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', -2)))),
    middle_name = SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(name), ' ', -2), ' ', 1),
    last_name   = SUBSTRING_INDEX(TRIM(name), ' ', -1),
    surname     = SUBSTRING_INDEX(TRIM(name), ' ', -1)
WHERE (first_name IS NULL OR first_name = '')
  AND (last_name IS NULL OR last_name = '')
  AND (LENGTH(TRIM(name)) - LENGTH(REPLACE(TRIM(name), ' ', ''))) > 2;

-- Single word
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

SELECT 'Migration v6 completed: Name segregation successful.' AS status;
