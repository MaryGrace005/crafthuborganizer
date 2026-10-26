-- ============================================================
-- CraftHub Organizer — Migration v9
-- Resize Name Columns to Match Frontend Input Limits
-- ============================================================
--
-- Why these changes are made:
-- The frontend forms enforce the following maxlength values:
--   first_name  → maxlength="20"  (VARCHAR 20)
--   middle_name → maxlength="15"  (VARCHAR 15)
--   last_name   → maxlength="20"  (VARCHAR 20)
--   surname     → maxlength="20"  (VARCHAR 20)
--
-- Keeping DB column sizes consistent with frontend input limits
-- prevents accepting data longer than the UI allows and ensures
-- accurate schema documentation.
--
-- Previous v7 had these as VARCHAR(50). No existing data exceeds
-- the new limits (all real names are well within these bounds).
-- ============================================================

USE crafthub;

ALTER TABLE users
    MODIFY COLUMN first_name  VARCHAR(20) NULL COMMENT 'Max 20 chars — matches frontend maxlength',
    MODIFY COLUMN middle_name VARCHAR(15) NULL COMMENT 'Max 15 chars — matches frontend maxlength',
    MODIFY COLUMN last_name   VARCHAR(20) NULL COMMENT 'Max 20 chars — matches frontend maxlength',
    MODIFY COLUMN surname     VARCHAR(20) NULL COMMENT 'Max 20 chars — matches frontend maxlength';

SELECT 'Migration v9 completed: Name column lengths aligned with frontend input limits.' AS status;
