-- ============================================================
-- CraftHub Organizer — Migration v7
-- Optimize Variable Lengths & Numeric Ranges for Realistic Data
-- ============================================================
--
-- Why these changes are made:
-- 1. Mobile number (contact_no): Philippine mobile numbers are strictly 11 digits (09XXXXXXXXX).
--    Using VARCHAR(11) prevents storing invalid bloated text (e.g. 60 or 20 chars) and preserves
--    the leading zero ('09'), which standard INT would strip while also overflowing standard 32-bit INT.
-- 2. Personal Names (first_name, middle_name, last_name, surname): VARCHAR(50) is realistic and clean,
--    replacing bloated VARCHAR(100).
-- 3. Full Name (name): VARCHAR(100) fits concatenated full names safely without 150/255 waste.
-- 4. Email: VARCHAR(100) is standard for personal & business emails.
-- 5. Venue Capacity & Event Guest Count: SMALLINT UNSIGNED (0 to 65,535) is the realistic range
--    for guests/capacity (10-5,000 guests), avoiding wasteful INT (2.1 billion).
-- 6. Package Max Slots: TINYINT UNSIGNED (0 to 255) is the realistic range for daily/monthly slots (1-50).
-- 7. Identifiers & References (booking_reference, or_number): VARCHAR(20) fits generated codes
--    like 'BK-2026-00001' (13 chars) and 'OR-2026-0001' (12 chars).
-- 8. Payment Method & References: VARCHAR(20) and VARCHAR(50) match realistic transaction codes.
-- 9. File Size: INT UNSIGNED ensures non-negative byte count up to 4GB.
-- ============================================================

USE crafthub;

-- 1. Users Table
ALTER TABLE users 
    MODIFY COLUMN contact_no VARCHAR(11) NULL COMMENT '11-digit mobile number (09XXXXXXXXX)',
    MODIFY COLUMN first_name VARCHAR(50) NULL,
    MODIFY COLUMN middle_name VARCHAR(50) NULL,
    MODIFY COLUMN last_name VARCHAR(50) NULL,
    MODIFY COLUMN surname VARCHAR(50) NULL,
    MODIFY COLUMN name VARCHAR(100) NOT NULL,
    MODIFY COLUMN email VARCHAR(100) NOT NULL,
    MODIFY COLUMN gender VARCHAR(20) NULL,
    MODIFY COLUMN business_name VARCHAR(100) NULL,
    MODIFY COLUMN event_type_interest VARCHAR(50) NULL,
    MODIFY COLUMN guest_count_est SMALLINT UNSIGNED NULL;

-- 2. Packages Table
ALTER TABLE packages 
    MODIFY COLUMN package_name VARCHAR(80) NOT NULL,
    MODIFY COLUMN max_slots TINYINT UNSIGNED NOT NULL DEFAULT 5;

-- 3. Package Components Table
ALTER TABLE package_components 
    MODIFY COLUMN name VARCHAR(80) NOT NULL;

-- 4. Venues Table
ALTER TABLE venues 
    MODIFY COLUMN venue_name VARCHAR(80) NOT NULL,
    MODIFY COLUMN capacity SMALLINT UNSIGNED NULL,
    MODIFY COLUMN location VARCHAR(150) NULL;

-- 5. Bookings Table
ALTER TABLE bookings 
    MODIFY COLUMN booking_reference VARCHAR(20) NULL,
    MODIFY COLUMN guest_count SMALLINT UNSIGNED DEFAULT 1;

-- 6. Payments Table
ALTER TABLE payments 
    MODIFY COLUMN or_number VARCHAR(20) NOT NULL,
    MODIFY COLUMN payment_method VARCHAR(20) DEFAULT 'cash',
    MODIFY COLUMN reference_no VARCHAR(50) NULL;

-- 7. Payment Submissions Table
ALTER TABLE payment_submissions 
    MODIFY COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'gcash',
    MODIFY COLUMN reference_no VARCHAR(50) NOT NULL;

-- 8. Notifications Table
ALTER TABLE notifications 
    MODIFY COLUMN title VARCHAR(100) NOT NULL,
    MODIFY COLUMN type VARCHAR(20) NOT NULL DEFAULT 'info';

-- 9. Booking Images Table
ALTER TABLE booking_images 
    MODIFY COLUMN mime_type VARCHAR(50) NOT NULL DEFAULT 'image/jpeg',
    MODIFY COLUMN file_size INT UNSIGNED NOT NULL DEFAULT 0;

SELECT 'Migration v7 completed: Realistic column ranges and types successfully applied.' AS status;
