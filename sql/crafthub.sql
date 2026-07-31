-- Create database
CREATE DATABASE IF NOT EXISTS crafthub;
USE crafthub;

-- Table: users
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    contact_no VARCHAR(20),
    address TEXT,
    ip_address VARCHAR(45) NULL,                           -- Locked device IP per Customer account rule
    role ENUM('customer','staff','cashier','admin') DEFAULT 'customer',
    security_question VARCHAR(255),
    security_answer_hash VARCHAR(255),
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table: packages
CREATE TABLE IF NOT EXISTS packages (
    package_id INT AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(100) NOT NULL,
    event_type ENUM('Wedding','Birthday','Debut','Christening') NOT NULL,
    base_price DECIMAL(10,2) NOT NULL,
    max_slots INT NOT NULL DEFAULT 5,                      -- Max bookings allowed before package is fully booked
    description TEXT,
    status ENUM('active','inactive') DEFAULT 'active'
);

-- Table: package_components (bundled items)
CREATE TABLE IF NOT EXISTS package_components (
    component_id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    category ENUM('venue','food','photography','decoration') NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (package_id) REFERENCES packages(package_id) ON DELETE CASCADE
);

-- Table: venues (standalone, used for booking)
CREATE TABLE IF NOT EXISTS venues (
    venue_id INT AUTO_INCREMENT PRIMARY KEY,
    venue_name VARCHAR(100) NOT NULL,
    capacity INT,
    location VARCHAR(255),
    availability_status ENUM('available','unavailable') DEFAULT 'available'
);

-- Table: bookings
CREATE TABLE IF NOT EXISTS bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_reference VARCHAR(50) UNIQUE,
    customer_id INT NOT NULL,
    package_id INT NOT NULL,
    venue_id INT NULL,
    event_date DATE NOT NULL,
    event_time TIME DEFAULT '09:00:00',
    event_type ENUM('Wedding','Birthday','Debut','Christening') NOT NULL DEFAULT 'Wedding',
    guest_count INT DEFAULT 1,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('Pending','Confirmed','Paid','Completed','Cancelled') DEFAULT 'Pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES packages(package_id) ON DELETE CASCADE,
    FOREIGN KEY (venue_id) REFERENCES venues(venue_id) ON DELETE SET NULL
);

-- Table: booking_components (selected extras)
CREATE TABLE IF NOT EXISTS booking_components (
    booking_component_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    component_id INT NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (component_id) REFERENCES package_components(component_id) ON DELETE CASCADE
);

-- Table: payments
CREATE TABLE IF NOT EXISTS payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    cashier_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_type ENUM('downpayment','full','balance') NOT NULL DEFAULT 'full',
    payment_method VARCHAR(50) DEFAULT 'cash',
    reference_no VARCHAR(100),
    or_number VARCHAR(50) UNIQUE NOT NULL,
    notes TEXT,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (cashier_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Table: audit_logs
CREATE TABLE IF NOT EXISTS audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(255) NOT NULL,
    table_affected VARCHAR(50),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Table: booking_images (photos/files attached to an event booking)
CREATE TABLE IF NOT EXISTS booking_images (
    image_id       INT AUTO_INCREMENT PRIMARY KEY,
    booking_id     INT NOT NULL,
    uploaded_by    INT NULL,                              -- user who uploaded (customer, cashier, or admin)
    image_type     ENUM(
                       'event_photo',                     -- photo from the actual event
                       'payment_proof',                   -- screenshot / receipt image
                       'contract',                        -- signed contract scan
                       'venue_photo',                     -- venue inspection photo
                       'decoration_photo',                -- decoration reference photo
                       'other'
                   ) NOT NULL DEFAULT 'event_photo',
    image_path     VARCHAR(500) NOT NULL,                 -- relative path: uploads/booking_images/filename.jpg
    original_name  VARCHAR(255) NOT NULL,                 -- original filename uploaded by the user
    mime_type      VARCHAR(100) DEFAULT 'image/jpeg',     -- image/jpeg, image/png, application/pdf, etc.
    file_size      INT UNSIGNED DEFAULT 0,                -- file size in bytes
    caption        VARCHAR(500) NULL,                     -- optional user caption / description
    is_public      TINYINT(1) DEFAULT 0,                  -- 1 = visible to customer, 0 = admin/cashier only
    uploaded_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id)  REFERENCES bookings(booking_id)  ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id)        ON DELETE SET NULL
);

-- Migration: add booking_images table if upgrading an existing database
-- (safe to run even if column already exists — IF NOT EXISTS prevents errors)
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS booking_reference VARCHAR(50) UNIQUE AFTER booking_id;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS event_time TIME DEFAULT '09:00:00' AFTER event_date;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_amount;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS notes TEXT AFTER status;

ALTER TABLE payments ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) DEFAULT 'cash' AFTER payment_type;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS reference_no VARCHAR(100) AFTER payment_method;
ALTER TABLE payments ADD COLUMN IF NOT EXISTS notes TEXT AFTER or_number;

-- Seed Data (Default Admin, Cashier, Customer users)
INSERT IGNORE INTO users (user_id, name, email, password, role, security_question, security_answer_hash, status) VALUES
(1, 'Admin User', 'admin@crafthub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'What is your pet name?', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active'),
(2, 'Cashier User', 'cashier@crafthub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'What is your favorite color?', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active'),
(3, 'John Doe', 'customer@crafthub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'What is your mother maiden name?', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active');

-- Seed Packages
INSERT IGNORE INTO packages (package_id, package_name, event_type, base_price, description) VALUES
(1, 'Elegant Wedding', 'Wedding', 50000.00, 'Complete wedding package with premium venue, catering, photo, and décor.'),
(2, 'Fun Birthday Bash', 'Birthday', 15000.00, 'Fun-filled birthday package with food, decorations, and photo.'),
(3, 'Grand Debut', 'Debut', 30000.00, 'Sophisticated debut package with catering, photo, and elegant décor.'),
(4, 'Christening Blessing', 'Christening', 10000.00, 'Simple yet elegant christening package with venue, food, and photo.');

-- Seed Package Components
INSERT IGNORE INTO package_components (component_id, package_id, category, name, description, price) VALUES
(1, 1, 'venue', 'Grand Ballroom', 'Spacious ballroom for 200 guests', 20000.00),
(2, 1, 'food', '5-Course Plated Dinner', 'Includes appetizer, soup, main, dessert, drinks', 15000.00),
(3, 1, 'photography', 'Premium Photo & Video', '2 photographers, 1 videographer, album', 10000.00),
(4, 1, 'decoration', 'Floral Arrangements', 'Fresh flowers, centerpieces, stage backdrop', 5000.00);

-- Seed Venues
INSERT IGNORE INTO venues (venue_id, venue_name, capacity, location, availability_status) VALUES
(1, 'Grand Ballroom', 200, '123 Main St, City', 'available'),
(2, 'Garden Pavilion', 100, '456 Park Ave, City', 'available');

-- Seed Sample Booking
INSERT IGNORE INTO bookings (booking_id, booking_reference, customer_id, package_id, venue_id, event_date, event_time, event_type, guest_count, total_amount, amount_paid, status) VALUES
(1, 'BK-2026-00001', 3, 1, 1, '2026-08-15', '09:00:00', 'Wedding', 150, 50000.00, 20000.00, 'Confirmed');

-- Seed Sample Payment
INSERT IGNORE INTO payments (payment_id, booking_id, cashier_id, amount_paid, payment_type, payment_method, or_number) VALUES
(1, 1, 2, 20000.00, 'downpayment', 'cash', 'OR-2026-0001');