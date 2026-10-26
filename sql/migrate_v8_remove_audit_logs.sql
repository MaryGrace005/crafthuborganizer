-- ============================================================
-- CraftHub Organizer — Migration v8
-- Remove Audit Log Feature and Drop audit_logs Table
-- ============================================================

USE crafthub;

-- Drop audit_logs table if it exists
DROP TABLE IF EXISTS audit_logs;

SELECT 'Migration v8 completed: audit_logs table removed successfully.' AS status;
