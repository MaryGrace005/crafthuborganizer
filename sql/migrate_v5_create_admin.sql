-- ============================================================
-- CraftHub Organizer — Migration v5
-- Create / Provision Master Admin Account
-- ============================================================

USE crafthub;

-- Ensure users table exists with admin role option
ALTER TABLE users MODIFY COLUMN role ENUM('customer','staff','cashier','admin') DEFAULT 'customer';
ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','pending_approval','rejected') DEFAULT 'active';

-- Seed / Update Master Administrator
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

SELECT 'Migration v5 completed: Admin account provisioned successfully.' AS status;
