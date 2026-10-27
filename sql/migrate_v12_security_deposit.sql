-- ============================================================
--  Migration v12 — Security Deposit Feature
--  Adds security_deposit and deposit_status to bookings table
-- ============================================================

-- Add security_deposit column (amount held from customer)
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS security_deposit DECIMAL(10,2) NOT NULL DEFAULT 0.00
        COMMENT 'Refundable security deposit collected at time of booking'
        AFTER total_amount;

-- Add deposit_status to track whether it was returned or forfeited
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS deposit_status ENUM('held','returned','forfeited') NOT NULL DEFAULT 'held'
        COMMENT 'held = currently with us; returned = given back (no damage); forfeited = kept due to damage'
        AFTER security_deposit;

-- Add deposit_returned_at and deposit_note for audit trail
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS deposit_returned_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When the deposit was returned or forfeited'
        AFTER deposit_status;

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS deposit_note TEXT NULL DEFAULT NULL
        COMMENT 'Reason for forfeiture or return note'
        AFTER deposit_returned_at;

-- Add security_deposit column to packages table
ALTER TABLE packages
    ADD COLUMN IF NOT EXISTS security_deposit DECIMAL(10,2) NOT NULL DEFAULT 2000.00
        COMMENT 'Default refundable security deposit for this package'
        AFTER base_price;

