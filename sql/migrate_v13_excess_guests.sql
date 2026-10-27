-- Migration v13: Add excess guest add-on pricing to packages and bookings
-- Supports automatic per-head add-on pricing when guest count exceeds package limit

-- Add columns to packages
ALTER TABLE packages ADD COLUMN IF NOT EXISTS max_guests SMALLINT UNSIGNED NOT NULL DEFAULT 75 AFTER base_price;
ALTER TABLE packages ADD COLUMN IF NOT EXISTS extra_guest_price DECIMAL(10,2) NOT NULL DEFAULT 75.00 AFTER max_guests;

-- Add columns to bookings
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS excess_guests SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER guest_count;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS excess_guest_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER excess_guests;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS package_guest_limit SMALLINT UNSIGNED NOT NULL DEFAULT 75 AFTER excess_guest_fee;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS per_guest_fee DECIMAL(10,2) NOT NULL DEFAULT 75.00 AFTER package_guest_limit;

-- Update existing packages with sensible defaults based on event type (₱50-100 per head, distinct limits)
UPDATE packages SET max_guests = 100, extra_guest_price = 100.00 WHERE event_type = 'Wedding';
UPDATE packages SET max_guests = 80,  extra_guest_price = 80.00  WHERE event_type = 'Debut';
UPDATE packages SET max_guests = 60,  extra_guest_price = 75.00  WHERE event_type = 'Birthday';
UPDATE packages SET max_guests = 40,  extra_guest_price = 50.00  WHERE event_type = 'Christening';
UPDATE packages SET max_guests = 75,  extra_guest_price = 75.00  WHERE event_type NOT IN ('Wedding', 'Debut', 'Birthday', 'Christening');
