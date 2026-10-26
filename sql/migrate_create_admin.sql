-- ============================================================
-- CraftHub Organizer — Migration: Create New Administrator
-- File: sql/migrate_create_admin.sql (v5)
-- ============================================================

USE crafthub;

-- ------------------------------------------------------------
-- Insert or Update New Admin Account
-- Default Credentials:
--   Email:    admin_master@crafthub.com
--   Password: password
-- Note: Replace password hash with custom bcrypt hash if desired,
-- or use the web script: http://localhost/crafthuborganizer/migrate_admin.php
-- ------------------------------------------------------------

INSERT INTO users (
    name,
    first_name,
    middle_name,
    last_name,
    surname,
    email,
    password,
    role,
    status,
    contact_no,
    address,
    security_question,
    security_answer_hash
) VALUES (
    'Super Admin',
    'Super',
    '',
    'Admin',
    'Admin',
    'admin_master@crafthub.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Default password: 'password'
    'admin',
    'active',
    '09170009999',
    'CraftHub Central HQ',
    'What is the name of your primary organization?',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
)
ON DUPLICATE KEY UPDATE
    first_name  = 'Super',
    last_name   = 'Admin',
    surname     = 'Admin',
    role        = 'admin',
    status      = 'active';

-- Output status confirmation
SELECT user_id, name, email, role, status, created_at 
FROM users 
WHERE email = 'admin_master@crafthub.com';
