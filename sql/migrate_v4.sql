-- ============================================================
-- CraftHub Organizer — Migration v4
-- Comprehensive Enhancements:
-- 1. Cancellation Reason on bookings
-- 2. Notifications table
-- 3. Payment Submissions (Proof Upload & Verification) table
-- 4. Booking Components support for add-ons
-- 5. Soft Delete / Archived status support
-- ============================================================

USE crafthub;

-- 1. Add cancellation_reason to bookings table
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS cancellation_reason TEXT NULL AFTER notes;

-- 2. Create notifications table
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    title           VARCHAR(150) NOT NULL,
    message         TEXT NOT NULL,
    type            VARCHAR(50) NOT NULL DEFAULT 'info',     -- info, success, warning, danger
    link            VARCHAR(255) NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_user_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create payment_submissions table (Customer online payment proof verification queue)
CREATE TABLE IF NOT EXISTS payment_submissions (
    submission_id   INT AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT NOT NULL,
    customer_id     INT NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    payment_method  VARCHAR(50) NOT NULL DEFAULT 'gcash',   -- gcash, maya, bank_transfer, cash
    reference_no    VARCHAR(100) NOT NULL,
    proof_image     VARCHAR(500) NOT NULL,
    notes           TEXT NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason TEXT NULL,
    reviewed_by     INT NULL,
    reviewed_at     TIMESTAMP NULL DEFAULT NULL,
    payment_id      INT NULL,                              -- linked payment_id once approved
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sub_booking (booking_id),
    INDEX idx_sub_status (status),
    FOREIGN KEY (booking_id)  REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(user_id)       ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Ensure booking_components has proper columns
CREATE TABLE IF NOT EXISTS booking_components (
    booking_component_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id           INT NOT NULL,
    component_id         INT NOT NULL,
    price_at_booking     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bc_booking (booking_id),
    FOREIGN KEY (booking_id)   REFERENCES bookings(booking_id)            ON DELETE CASCADE,
    FOREIGN KEY (component_id) REFERENCES package_components(component_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add price_at_booking column to booking_components if already exists
ALTER TABLE booking_components ADD COLUMN IF NOT EXISTS price_at_booking DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER component_id;

-- Done!
SELECT 'Migration v4 completed successfully.' AS status;
