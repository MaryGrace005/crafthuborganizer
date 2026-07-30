-- Create database
CREATE DATABASE IF NOT EXISTS crafthub;
USE crafthub;

-- Table: users
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    contact_no VARCHAR(20),
    address TEXT,
    role ENUM('customer','cashier','admin') DEFAULT 'customer',
    security_question VARCHAR(255),
    security_answer_hash VARCHAR(255),
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table: packages
CREATE TABLE packages (
    package_id INT AUTO_INCREMENT PRIMARY KEY,
    package_name VARCHAR(100) NOT NULL,
    event_type ENUM('Wedding','Birthday','Debut','Christening') NOT NULL,
    base_price DECIMAL(10,2) NOT NULL,
    description TEXT,
    status ENUM('active','inactive') DEFAULT 'active'
);

-- Table: package_components (bundled items)
CREATE TABLE package_components (
    component_id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    category ENUM('venue','food','photography','decoration') NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (package_id) REFERENCES packages(package_id) ON DELETE CASCADE
);

-- Table: venues (standalone, used for booking)
CREATE TABLE venues (
    venue_id INT AUTO_INCREMENT PRIMARY KEY,
    venue_name VARCHAR(100) NOT NULL,
    capacity INT,
    location VARCHAR(255),
    availability_status ENUM('available','unavailable') DEFAULT 'available'
);

-- Table: bookings
CREATE TABLE bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    package_id INT NOT NULL,
    venue_id INT NOT NULL,
    event_date DATE NOT NULL,
    event_type ENUM('Wedding','Birthday','Debut','Christening') NOT NULL,
    guest_count INT,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('Pending','Confirmed','Paid','Completed','Cancelled') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(user_id),
    FOREIGN KEY (package_id) REFERENCES packages(package_id),
    FOREIGN KEY (venue_id) REFERENCES venues(venue_id)
);

-- Table: booking_components (selected extras)
CREATE TABLE booking_components (
    booking_component_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    component_id INT NOT NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (component_id) REFERENCES package_components(component_id)
);

-- Table: payments
CREATE TABLE payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    cashier_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_type ENUM('downpayment','full','balance') NOT NULL,
    or_number VARCHAR(50) UNIQUE NOT NULL,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id),
    FOREIGN KEY (cashier_id) REFERENCES users(user_id)
);

-- Table: audit_logs
CREATE TABLE audit_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    table_affected VARCHAR(50),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Seed Data

-- Users (passwords: admin123, cashier123, customer123 hashed with PASSWORD_DEFAULT)
INSERT INTO users (name, email, password, role, security_question, security_answer_hash, status) VALUES
('Admin User', 'admin@crafthub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'What is your pet name?', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active'),
('Cashier User', 'cashier@crafthub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'What is your favorite color?', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active'),
('John Doe', 'customer@crafthub.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'What is your mother maiden name?', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active');

-- Packages
INSERT INTO packages (package_name, event_type, base_price, description) VALUES
('Elegant Wedding', 'Wedding', 50000.00, 'Complete wedding package with premium venue, catering, photo, and décor.'),
('Fun Birthday Bash', 'Birthday', 15000.00, 'Fun-filled birthday package with food, decorations, and photo.'),
('Grand Debut', 'Debut', 30000.00, 'Sophisticated debut package with catering, photo, and elegant décor.'),
('Christening Blessing', 'Christening', 10000.00, 'Simple yet elegant christening package with venue, food, and photo.');

-- Package Components (sample for Elegant Wedding)
INSERT INTO package_components (package_id, category, name, description, price) VALUES
(1, 'venue', 'Grand Ballroom', 'Spacious ballroom for 200 guests', 20000.00),
(1, 'food', '5-Course Plated Dinner', 'Includes appetizer, soup, main, dessert, drinks', 15000.00),
(1, 'photography', 'Premium Photo & Video', '2 photographers, 1 videographer, album', 10000.00),
(1, 'decoration', 'Floral Arrangements', 'Fresh flowers, centerpieces, stage backdrop', 5000.00);

-- Venues
INSERT INTO venues (venue_name, capacity, location, availability_status) VALUES
('Grand Ballroom', 200, '123 Main St, City', 'available'),
('Garden Pavilion', 100, '456 Park Ave, City', 'available');

-- Sample Booking (for testing)
INSERT INTO bookings (customer_id, package_id, venue_id, event_date, event_type, guest_count, total_amount, status) VALUES
(3, 1, 1, '2026-08-15', 'Wedding', 150, 50000.00, 'Confirmed');

-- Sample Payment (for testing)
INSERT INTO payments (booking_id, cashier_id, amount_paid, payment_type, or_number) VALUES
(1, 2, 20000.00, 'downpayment', 'OR-2026-0001');