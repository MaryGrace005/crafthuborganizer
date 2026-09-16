-- ============================================================
-- CraftHub Organizer — Migration v3
-- Booking Availability & Venue Restriction
-- Run ONCE on an existing database.
-- ============================================================

USE crafthub;

-- -------------------------------------------------------
-- 1. Index for fast venue+date conflict lookups
-- -------------------------------------------------------
-- Avoid duplicate if already exists
SET @idx_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'bookings'
      AND INDEX_NAME   = 'idx_venue_date'
);

SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_venue_date ON bookings (venue_id, event_date)',
    'SELECT ''index idx_venue_date already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -------------------------------------------------------
-- 2. Drop old trigger if it exists (idempotent re-run)
-- -------------------------------------------------------
DROP TRIGGER IF EXISTS trg_prevent_double_booking_insert;
DROP TRIGGER IF EXISTS trg_prevent_double_booking_update;

-- -------------------------------------------------------
-- 3. BEFORE INSERT trigger — block double-booking
--    Only active statuses (Pending, Confirmed, Paid)
--    block a venue+date slot. Cancelled rows are ignored.
-- -------------------------------------------------------
DELIMITER $$

CREATE TRIGGER trg_prevent_double_booking_insert
BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE conflict_count INT DEFAULT 0;

    -- Only check when a venue is actually selected
    IF NEW.venue_id IS NOT NULL THEN
        SELECT COUNT(*) INTO conflict_count
        FROM bookings
        WHERE venue_id   = NEW.venue_id
          AND event_date = NEW.event_date
          AND status NOT IN ('Cancelled');
    END IF;

    IF conflict_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'This venue is already booked on the selected date.';
    END IF;
END$$

-- -------------------------------------------------------
-- 4. BEFORE UPDATE trigger — block double-booking on update
--    (e.g., if admin moves a booking to a different date)
-- -------------------------------------------------------
CREATE TRIGGER trg_prevent_double_booking_update
BEFORE UPDATE ON bookings
FOR EACH ROW
BEGIN
    DECLARE conflict_count INT DEFAULT 0;

    IF NEW.venue_id IS NOT NULL
       AND (NEW.venue_id != OLD.venue_id OR NEW.event_date != OLD.event_date)
       AND NEW.status NOT IN ('Cancelled')
    THEN
        SELECT COUNT(*) INTO conflict_count
        FROM bookings
        WHERE venue_id   = NEW.venue_id
          AND event_date = NEW.event_date
          AND status NOT IN ('Cancelled')
          AND booking_id != NEW.booking_id;  -- exclude self
    END IF;

    IF conflict_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'This venue is already booked on the selected date.';
    END IF;
END$$

DELIMITER ;

-- -------------------------------------------------------
-- Done!
-- -------------------------------------------------------
SELECT 'Migration v3 completed: venue double-booking prevention is now active.' AS status;
