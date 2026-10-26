-- ============================================================
-- CraftHub Organizer — Migration v11
-- Adjust name and email column sizes
-- ============================================================
--
-- name  VARCHAR(65): fits full combined display names
--        (first 20 + space + middle 15 + space + last 20 = 57 max,
--         with 8 chars buffer for suffixes like Jr./Sr./III)
-- email VARCHAR(50): realistic personal/business email limit
--        (longest existing in DB was 28 chars; 50 gives headroom)
-- ============================================================

USE crafthub;

ALTER TABLE users
    MODIFY COLUMN name  VARCHAR(65) NOT NULL COMMENT 'Auto-built display name; max 65 chars',
    MODIFY COLUMN email VARCHAR(50) NOT NULL;

SELECT 'Migration v11 completed: name → VARCHAR(65), email → VARCHAR(50).' AS status;
