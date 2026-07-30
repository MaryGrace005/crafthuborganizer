-- ============================================================
-- CraftHub Organizer - Database Migration Script
-- Run this ONCE on an existing database to add new columns
-- ============================================================

-- 1. Add ip_address column to users (unique per customer)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) UNIQUE NULL
    COMMENT 'Strict 1 IP per Customer account rule';

-- 2. Add max_slots column to packages
ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS max_slots INT NOT NULL DEFAULT 5
    COMMENT 'Max bookings allowed before package is fully booked';

-- 3. Add image_url column to packages
ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NULL
    COMMENT 'Sample image preview URL for package';

-- 4. Create booking_images table (if not already present)
CREATE TABLE IF NOT EXISTS booking_images (
    image_id      INT AUTO_INCREMENT PRIMARY KEY,
    booking_id    INT           NOT NULL,
    uploaded_by   INT           NOT NULL,
    file_name     VARCHAR(255)  NOT NULL,
    file_path     VARCHAR(500)  NOT NULL,
    mime_type     VARCHAR(100)  NOT NULL DEFAULT 'image/jpeg',
    file_size     INT           NOT NULL DEFAULT 0 COMMENT 'in bytes',
    caption       VARCHAR(255)  NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_booking_id (booking_id),
    INDEX idx_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Done!
SELECT 'Migration completed successfully.' AS status;
