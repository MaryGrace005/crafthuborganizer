<?php
// ============================================================
//  Helper Functions - CraftHub Organizer
// ============================================================

require_once __DIR__ . '/../config/database.php';

// -----------------------------------------------
//  Input Sanitization
// -----------------------------------------------
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// -----------------------------------------------
//  Redirect
// -----------------------------------------------
function redirect(string $url): void {
    header("Location: $url");
    exit();
}

// -----------------------------------------------
//  Flash Messages (Session-based)
// -----------------------------------------------
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function displayFlash(): void {
    $flash = getFlash();
    if ($flash) {
        $type    = htmlspecialchars($flash['type']);
        $message = htmlspecialchars($flash['message']);
        $icons   = ['success' => '✓', 'error' => '✗', 'warning' => '⚠', 'info' => 'ℹ'];
        $icon    = $icons[$type] ?? 'ℹ';
        echo "<div class=\"alert alert-{$type}\"><span class=\"alert-icon\">{$icon}</span> {$message}</div>";
    }
}

// -----------------------------------------------
//  Currency Formatting
// -----------------------------------------------
function formatCurrency($amount): string {
    $val = (float)($amount ?? 0);
    return '₱ ' . number_format($val, 2);
}

// -----------------------------------------------
//  Booking Reference Generator
// -----------------------------------------------
// -----------------------------------------------
//  Account ID Code Generator (approval-time)
// -----------------------------------------------
function generateAccountIdCode(): string {
    $db = getDB();
    // Insert into the sequence table — AUTO_INCREMENT guarantees a unique value
    // even under concurrent requests, without needing SELECT MAX() + retry logic.
    $db->exec("INSERT INTO account_id_seq (dummy) VALUES (0)");
    $seq = (int)$db->lastInsertId();
    return 'TH-' . str_pad($seq, 6, '0', STR_PAD_LEFT);
}

// -----------------------------------------------
//  Get Next Account ID Code Preview
// -----------------------------------------------
function getNextAccountIdCodePreview(): string {
    try {
        $db = getDB();
        $stmt = $db->query("SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_id_seq'");
        $val = $stmt->fetchColumn();
        $next = $val ? (int)$val : 1;
        return 'TH-' . str_pad($next, 6, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        return 'TH-000123';
    }
}

// -----------------------------------------------
//  Booking Reference Generator
// -----------------------------------------------
function generateBookingRef(): string {
    $db   = getDB();
    $year = date('Y');
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM bookings WHERE YEAR(created_at) = $year");
    $count = ($stmt->fetch()['cnt'] ?? 0) + 1;
    return 'BK-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
}

function getBookingRef(array $b): string {
    if (!empty($b['booking_reference'])) {
        return $b['booking_reference'];
    }
    $id = $b['booking_id'] ?? $b['id'] ?? 0;
    return 'BK-' . date('Y') . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

// -----------------------------------------------
//  Audit Logging
// -----------------------------------------------
function logAudit(int $userId, string $action, string $description, string $table = ''): void {
    try {
        $db   = getDB();
        $act  = $action . ($description ? ": $description" : "");
        if ($userId > 0) {
            $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, table_affected) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $act, $table]);
        } else {
            $stmt = $db->prepare("INSERT INTO audit_logs (action, table_affected) VALUES (?, ?)");
            $stmt->execute([$act, $table]);
        }
    } catch (Exception $e) {
        // Silent fail — audit logging should never break the app
    }
}

// -----------------------------------------------
//  Status Badge HTML
// -----------------------------------------------
function statusBadge(?string $status): string {
    $statusStr = $status ?? 'unknown';
    $map = [
        'active'           => 'success',
        'available'        => 'success',
        'confirmed'        => 'success',
        'completed'        => 'info',
        'paid'             => 'success',
        'pending'          => 'warning',
        'partial'          => 'warning',
        'unpaid'           => 'danger',
        'inactive'         => 'secondary',
        'cancelled'        => 'danger',
        'maintenance'      => 'warning',
        'banned'           => 'danger',
        'pending_approval' => 'warning',
        'rejected'         => 'danger',
    ];
    $labels = [
        'pending_approval' => 'Pending Approval',
        'rejected'         => 'Rejected',
    ];
    $class = $map[strtolower($statusStr)] ?? 'secondary';
    $label = $labels[$statusStr] ?? ucfirst($statusStr);
    return "<span class=\"badge badge-{$class}\">" . htmlspecialchars($label) . "</span>";
}

// -----------------------------------------------
//  Date Formatting
// -----------------------------------------------
function formatDate(string $date): string {
    return date('F j, Y', strtotime($date));
}

function formatDateTime(string $dt): string {
    return date('M j, Y g:i A', strtotime($dt));
}

// -----------------------------------------------
//  Pagination Helper
// -----------------------------------------------
function paginate(int $total, int $perPage, int $currentPage): array {
    $totalPages = (int)ceil($total / $perPage);
    $offset     = ($currentPage - 1) * $perPage;
    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $currentPage,
        'total_pages' => $totalPages,
        'offset'      => $offset,
    ];
}

// -----------------------------------------------
//  Get All Packages (active)
// -----------------------------------------------
function getActivePackages(): array {
    $db   = getDB();
    ensureDiscountColumns($db);
    $stmt = $db->query("SELECT package_id AS id, package_id, package_name AS name, package_name,
        base_price AS price, base_price, description, status,
        COALESCE(full_payment_discount_percent,10) AS full_payment_discount_percent,
        COALESCE(downpayment_discount_percent,5)  AS downpayment_discount_percent,
        COALESCE(downpayment_percent,50)          AS downpayment_percent,
        COALESCE(max_slots, 5)                    AS max_slots,
        (SELECT COUNT(*) FROM bookings WHERE package_id = p.package_id AND status NOT IN ('Cancelled')) AS booked_count,
        (SELECT COUNT(*) FROM bookings WHERE package_id = p.package_id AND status = 'Completed') AS completed_count,
        (SELECT COUNT(*) FROM bookings WHERE package_id = p.package_id AND status IN ('Pending','Confirmed','Paid')) AS active_booking_count,
        GREATEST(0, COALESCE(p.max_slots, 5) - (SELECT COUNT(*) FROM bookings WHERE package_id = p.package_id AND status IN ('Pending','Confirmed','Paid'))) AS available_slots
        FROM packages p WHERE status = 'active' ORDER BY base_price ASC");
    return $stmt->fetchAll();
}

// -----------------------------------------------
//  Get Booking by ID
// -----------------------------------------------
function getBookingById(int $id): ?array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT b.*, b.booking_id AS id, u.name AS customer_name, u.email AS customer_email, u.contact_no AS customer_phone,
               p.package_name AS package_name, p.base_price AS package_price,
               v.venue_name AS venue_name, v.location AS venue_address,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS amount_paid
        FROM bookings b
        JOIN users u ON b.customer_id = u.user_id
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id
        WHERE b.booking_id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// -----------------------------------------------
//  Ensure Approval Columns on Bookings Table
// -----------------------------------------------
function ensureApprovalColumns(?PDO $db = null): void {
    static $ensured = false;
    if ($ensured) return;
    $db = $db ?? getDB();
    try {
        $chk = $db->query("SHOW COLUMNS FROM bookings LIKE 'approved_at'")->fetch();
        if (!$chk) {
            $db->exec("ALTER TABLE bookings ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER status");
        }
    } catch (Exception $e) {}
    try {
        $chk2 = $db->query("SHOW COLUMNS FROM bookings LIKE 'approved_by'")->fetch();
        if (!$chk2) {
            $db->exec("ALTER TABLE bookings ADD COLUMN approved_by INT NULL AFTER approved_at");
        }
    } catch (Exception $e) {}
    $ensured = true;
}

// -----------------------------------------------
//  Ensure Discount Columns on Packages & Bookings
// -----------------------------------------------
function ensureDiscountColumns(?PDO $db = null): void {
    static $ensured = false;
    if ($ensured) return;
    $db = $db ?? getDB();

    // Packages table columns
    try {
        $pCols = [
            'full_payment_discount_percent' => "DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER base_price",
            'downpayment_discount_percent'  => "DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER full_payment_discount_percent",
            'downpayment_percent'           => "DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER downpayment_discount_percent",
        ];
        foreach ($pCols as $col => $def) {
            $chk = $db->query("SHOW COLUMNS FROM packages LIKE '{$col}'")->fetch();
            if (!$chk) {
                $db->exec("ALTER TABLE packages ADD COLUMN {$col} {$def}");
            }
        }
        // Set defaults for existing rows if needed
        $db->exec("UPDATE packages SET full_payment_discount_percent = 10.00 WHERE full_payment_discount_percent IS NULL OR full_payment_discount_percent = 0");
        $db->exec("UPDATE packages SET downpayment_discount_percent = 5.00 WHERE downpayment_discount_percent IS NULL OR downpayment_discount_percent = 0");
        $db->exec("UPDATE packages SET downpayment_percent = 50.00 WHERE downpayment_percent IS NULL OR downpayment_percent = 0");
    } catch (Exception $e) {}

    // Bookings table columns
    try {
        $bCols = [
            'payment_plan'         => "ENUM('full','downpayment') NOT NULL DEFAULT 'full' AFTER event_type",
            'original_amount'      => "DECIMAL(10,2) NULL DEFAULT NULL AFTER guest_count",
            'discount_percent'     => "DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER original_amount",
            'discount_amount'      => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_percent",
            'downpayment_percent'  => "DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER discount_amount",
            'required_downpayment' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER downpayment_percent",
        ];
        foreach ($bCols as $col => $def) {
            $chk = $db->query("SHOW COLUMNS FROM bookings LIKE '{$col}'")->fetch();
            if (!$chk) {
                $db->exec("ALTER TABLE bookings ADD COLUMN {$col} {$def}");
            }
        }
        // Backfill original_amount where null
        $db->exec("UPDATE bookings SET original_amount = total_amount WHERE original_amount IS NULL OR original_amount = 0");
    } catch (Exception $e) {}

    $ensured = true;
}

// -----------------------------------------------
//  Calculate Package Pricing with Discounts
// -----------------------------------------------
function calculatePackagePricing(float $basePrice, float $fullDiscountPct = 10.0, float $downDiscountPct = 5.0, float $downRequiredPct = 50.0, string $plan = 'full'): array {
    $basePrice       = round(max(0.0, $basePrice), 2);
    $fullDiscountPct = round(max(0.0, min(100.0, $fullDiscountPct)), 2);
    $downDiscountPct = round(max(0.0, min(100.0, $downDiscountPct)), 2);
    $downRequiredPct = round(max(1.0, min(100.0, $downRequiredPct ?: 50.0)), 2);

    $fullDiscountAmt = round($basePrice * ($fullDiscountPct / 100.0), 2);
    $fullPayPrice    = round(max(0.0, $basePrice - $fullDiscountAmt), 2);

    $downDiscountAmt = round($basePrice * ($downDiscountPct / 100.0), 2);
    $downTotalPrice  = round(max(0.0, $basePrice - $downDiscountAmt), 2);
    $reqDownpayment  = round($downTotalPrice * ($downRequiredPct / 100.0), 2);
    $downBalance     = round(max(0.0, $downTotalPrice - $reqDownpayment), 2);

    $isDp = ($plan === 'downpayment');
    $discountPercent = $isDp ? $downDiscountPct : $fullDiscountPct;
    $discountAmount  = $isDp ? $downDiscountAmt : $fullDiscountAmt;
    $discountedTotal = $isDp ? $downTotalPrice : $fullPayPrice;
    $downpaymentDue  = $isDp ? $reqDownpayment : $fullPayPrice;
    $balanceDue      = $isDp ? $downBalance : 0.0;

    return [
        'base_price'                   => $basePrice,
        'plan'                         => $plan,
        'discount_percent'             => $discountPercent,
        'discount_amount'              => $discountAmount,
        'discounted_total'             => $discountedTotal,
        'downpayment_due'              => $downpaymentDue,
        'balance_due'                  => $balanceDue,
        'full_discount_percent'        => $fullDiscountPct,
        'full_discount_amount'         => $fullDiscountAmt,
        'full_payment_price'           => $fullPayPrice,
        'downpayment_discount_percent' => $downDiscountPct,
        'downpayment_discount_amount'  => $downDiscountAmt,
        'downpayment_total_price'      => $downTotalPrice,
        'downpayment_required_percent' => $downRequiredPct,
        'required_downpayment_amount'  => $reqDownpayment,
        'downpayment_balance'          => $downBalance,
    ];
}

// -----------------------------------------------
//  Update Booking Payment Status
// -----------------------------------------------
function updateBookingPaymentStatus(int $bookingId): void {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT status, total_amount,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS amount_paid
        FROM bookings b WHERE b.booking_id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) return;

    if ($booking['status'] === 'Cancelled' || $booking['status'] === 'Completed') {
        return;
    }

    $total = round((float)$booking['total_amount'], 2);
    $paid  = round((float)$booking['amount_paid'], 2);

    if ($paid >= ($total - 0.005) && $total > 0) {
        $status = 'Paid';
    } elseif ($paid > 0.005) {
        $status = 'Confirmed';
    } else {
        // If 0 paid, keep Confirmed if already approved by cashier/staff, otherwise keep Pending
        $status = ($booking['status'] === 'Confirmed') ? 'Confirmed' : 'Pending';
    }

    $update = $db->prepare("UPDATE bookings SET status = ?, amount_paid = ? WHERE booking_id = ?");
    $update->execute([$status, $paid, $bookingId]);
}

// -----------------------------------------------
//  Get Dashboard Stats
// -----------------------------------------------
function getDashboardStats(string $role, int $userId = 0): array {
    $db    = getDB();
    $stats = [];

    if ($role === 'admin') {
        $stats['total_users']     = $db->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
        $stats['total_packages']  = $db->query("SELECT COUNT(*) FROM packages WHERE status = 'active'")->fetchColumn();
        $stats['total_bookings']  = $db->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
        $stats['total_revenue']   = $db->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments")->fetchColumn();
        $stats['pending_bookings']= $db->query("SELECT COUNT(*) FROM bookings WHERE status = 'Pending'")->fetchColumn();
        $stats['total_venues']    = $db->query("SELECT COUNT(*) FROM venues WHERE availability_status = 'available'")->fetchColumn();
    } elseif ($role === 'staff' || $role === 'cashier') {
        $stats['pending_payments']= $db->query("SELECT COUNT(*) FROM bookings WHERE status NOT IN ('Paid','Cancelled')")->fetchColumn();
        $stats['total_collected'] = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE cashier_id = ?");
        $stats['total_collected']->execute([$userId]);
        $stats['total_collected'] = $stats['total_collected']->fetchColumn();
        $stats['today_payments']  = $db->prepare("SELECT COUNT(*) FROM payments WHERE cashier_id = ? AND DATE(payment_date) = CURDATE()");
        $stats['today_payments']->execute([$userId]);
        $stats['today_payments']  = $stats['today_payments']->fetchColumn();
        $stats['confirmed_bookings'] = $db->query("SELECT COUNT(*) FROM bookings WHERE status = 'Confirmed'")->fetchColumn();
    } elseif ($role === 'customer') {
        $s = $db->prepare("SELECT COUNT(*) FROM bookings WHERE customer_id = ?"); $s->execute([$userId]);
        $stats['my_bookings'] = $s->fetchColumn();
        $s = $db->prepare("SELECT COUNT(*) FROM bookings WHERE customer_id = ? AND status = 'Pending'"); $s->execute([$userId]);
        $stats['pending'] = $s->fetchColumn();
        $s = $db->prepare("SELECT COUNT(*) FROM bookings WHERE customer_id = ? AND status = 'Confirmed'"); $s->execute([$userId]);
        $stats['confirmed'] = $s->fetchColumn();
        $s = $db->prepare("SELECT COALESCE(SUM(p.amount_paid),0) FROM payments p JOIN bookings b ON p.booking_id = b.booking_id WHERE b.customer_id = ?"); $s->execute([$userId]);
        $stats['total_paid'] = $s->fetchColumn();
    }

    return $stats;
}

// -----------------------------------------------
//  Venue + Date Availability Check
// -----------------------------------------------
/**
 * Check whether a venue is available on a given date.
 *
 * Only bookings with status Pending, Confirmed, or Paid
 * are counted as "blocking" the slot. Cancelled bookings
 * free the slot back up.
 *
 * @param int         $venueId          Venue to check
 * @param string      $date             Date string (Y-m-d)
 * @param int|null    $excludeBookingId Optional — exclude this booking (for edits)
 * @return bool  TRUE = slot is free, FALSE = already booked
 */
function isVenueDateAvailable(int $venueId, string $date, ?int $excludeBookingId = null): bool {
    $db     = getDB();
    $sql    = "SELECT COUNT(*) FROM bookings
               WHERE venue_id   = ?
                 AND event_date = ?
                 AND status NOT IN ('Cancelled')";
    $params = [$venueId, $date];

    if ($excludeBookingId !== null) {
        $sql     .= " AND booking_id != ?";
        $params[] = $excludeBookingId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * Get all booked dates for a venue within a date range.
 * Returns an array of 'Y-m-d' strings.
 *
 * @param int    $venueId
 * @param string $fromDate  Y-m-d  (default: today)
 * @param string $toDate    Y-m-d  (default: +12 months)
 * @return string[]
 */
function getVenueBookedDates(int $venueId, string $fromDate = '', string $toDate = ''): array {
    if (!$fromDate) $fromDate = date('Y-m-d');
    if (!$toDate)   $toDate   = date('Y-m-d', strtotime('+12 months'));

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT event_date
        FROM bookings
        WHERE venue_id   = ?
          AND event_date BETWEEN ? AND ?
          AND status NOT IN ('Cancelled')
        ORDER BY event_date ASC
    ");
    $stmt->execute([$venueId, $fromDate, $toDate]);
    return array_column($stmt->fetchAll(), 'event_date');
}

// -----------------------------------------------
//  Payment Due Date & Ongoing Payment Helpers
// -----------------------------------------------

/**
 * Compute or retrieve the payment due date for a booking.
 * Defaults to 7 days before event date (or 3 days after creation / 1 day before event if event is soon).
 */
function getBookingPaymentDueDate(array $booking): string {
    if (!empty($booking['payment_due_date'])) {
        return $booking['payment_due_date'];
    }
    if (!empty($booking['event_date'])) {
        $eventTs   = strtotime($booking['event_date']);
        $createdTs = !empty($booking['created_at']) ? strtotime($booking['created_at']) : time();
        $dueTs     = strtotime('-7 days', $eventTs);
        if ($dueTs <= $createdTs) {
            $altTs = strtotime('+3 days', $createdTs);
            $dueTs = min($altTs, strtotime('-1 day', $eventTs));
            if ($dueTs < $createdTs) {
                $dueTs = $eventTs;
            }
        }
        return date('Y-m-d', $dueTs);
    }
    return date('Y-m-d', strtotime('+7 days'));
}

/**
 * Fetch all active bookings for a customer that have an unpaid balance (ongoing payments).
 * Computes remaining balance, due date, days until due, and urgency status.
 *
 * @param int $customerId
 * @return array
 */
function getCustomerOngoingBookings(int $customerId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT b.*, b.booking_id AS id, p.package_name, v.venue_name,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS total_paid
        FROM bookings b
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id
        WHERE b.customer_id = ?
          AND b.status NOT IN ('Cancelled')
        ORDER BY b.event_date ASC
    ");
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll();

    $ongoing = [];
    $today = date('Y-m-d');
    $todayTs = strtotime($today);

    foreach ($rows as $b) {
        $total   = round((float)$b['total_amount'], 2);
        $paid    = round((float)$b['total_paid'], 2);
        $balance = round(max(0.0, $total - $paid), 2);

        // Only consider bookings with an unpaid balance (strictly greater than 0)
        if ($balance > 0.005) {
            $dueDate   = getBookingPaymentDueDate($b);
            $dueTs     = strtotime($dueDate);
            $daysLeft  = (int)round(($dueTs - $todayTs) / 86400);

            if ($daysLeft < 0) {
                $urgency = 'overdue';
                $urgencyLabel = 'Overdue by ' . abs($daysLeft) . ' day' . (abs($daysLeft) !== 1 ? 's' : '');
            } elseif ($daysLeft === 0) {
                $urgency = 'due_today';
                $urgencyLabel = 'Due Today';
            } elseif ($daysLeft <= 7) {
                $urgency = 'due_soon';
                $urgencyLabel = 'Due in ' . $daysLeft . ' day' . ($daysLeft !== 1 ? 's' : '');
            } else {
                $urgency = 'upcoming';
                $urgencyLabel = 'Due in ' . $daysLeft . ' days';
            }

            $b['calculated_balance'] = $balance;
            $b['effective_due_date'] = $dueDate;
            $b['days_left']          = $daysLeft;
            $b['urgency']            = $urgency;
            $b['urgency_label']      = $urgencyLabel;

            $ongoing[] = $b;
        }
    }

    return $ongoing;
}

/**
 * Check whether a customer has any ongoing (unpaid) booking.
 */
function hasCustomerOngoingPayment(int $customerId): bool {
    return count(getCustomerOngoingBookings($customerId)) > 0;
}

/**
 * Retrieve notifications for bookings with payment due soon (<= $daysThreshold days), due today, or overdue.
 */
function getCustomerPaymentDueAlerts(int $customerId, int $daysThreshold = 7): array {
    $ongoing = getCustomerOngoingBookings($customerId);
    $alerts  = [];

    foreach ($ongoing as $b) {
        if ($b['days_left'] <= $daysThreshold) {
            $alerts[] = $b;
        }
    }

    return $alerts;
}

// -----------------------------------------------
//  Ensure Schema Updates (V4 migration)
// -----------------------------------------------
function ensureV4Schema(?PDO $db = null): void {
    static $v4Done = false;
    if ($v4Done) return;
    $db = $db ?? getDB();

    try {
        // 1. cancellation_reason on bookings
        $col1 = $db->query("SHOW COLUMNS FROM bookings LIKE 'cancellation_reason'")->fetch();
        if (!$col1) {
            $db->exec("ALTER TABLE bookings ADD COLUMN cancellation_reason TEXT NULL AFTER notes");
        }
    } catch (Exception $e) {}

    try {
        // Attempt engine alignment to InnoDB if needed
        $db->exec("ALTER TABLE users ENGINE=InnoDB");
        $db->exec("ALTER TABLE bookings ENGINE=InnoDB");
    } catch (Exception $e) {}

    try {
        // 2. notifications table
        $db->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                notification_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id         INT NOT NULL,
                title           VARCHAR(150) NOT NULL,
                message         TEXT NOT NULL,
                type            VARCHAR(50) NOT NULL DEFAULT 'info',
                link            VARCHAR(255) NULL,
                is_read         TINYINT(1) NOT NULL DEFAULT 0,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_read (user_id, is_read),
                INDEX idx_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    notification_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id         INT NOT NULL,
                    title           VARCHAR(150) NOT NULL,
                    message         TEXT NOT NULL,
                    type            VARCHAR(50) NOT NULL DEFAULT 'info',
                    link            VARCHAR(255) NULL,
                    is_read         TINYINT(1) NOT NULL DEFAULT 0,
                    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_read (user_id, is_read),
                    INDEX idx_user_created (user_id, created_at)
                )
            ");
        } catch (Exception $e2) {}
    }

    try {
        // 3. payment_submissions table
        $db->exec("
            CREATE TABLE IF NOT EXISTS payment_submissions (
                submission_id    INT AUTO_INCREMENT PRIMARY KEY,
                booking_id       INT NOT NULL,
                customer_id      INT NOT NULL,
                amount           DECIMAL(10,2) NOT NULL,
                payment_method   VARCHAR(50) NOT NULL DEFAULT 'gcash',
                reference_no     VARCHAR(100) NOT NULL,
                proof_image      VARCHAR(500) NOT NULL,
                notes            TEXT NULL,
                status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                rejection_reason TEXT NULL,
                reviewed_by      INT NULL,
                reviewed_at      TIMESTAMP NULL DEFAULT NULL,
                payment_id       INT NULL,
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sub_booking (booking_id),
                INDEX idx_sub_customer (customer_id),
                INDEX idx_sub_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS payment_submissions (
                    submission_id    INT AUTO_INCREMENT PRIMARY KEY,
                    booking_id       INT NOT NULL,
                    customer_id      INT NOT NULL,
                    amount           DECIMAL(10,2) NOT NULL,
                    payment_method   VARCHAR(50) NOT NULL DEFAULT 'gcash',
                    reference_no     VARCHAR(100) NOT NULL,
                    proof_image      VARCHAR(500) NOT NULL,
                    notes            TEXT NULL,
                    status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                    rejection_reason TEXT NULL,
                    reviewed_by      INT NULL,
                    reviewed_at      TIMESTAMP NULL DEFAULT NULL,
                    payment_id       INT NULL,
                    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_sub_booking (booking_id),
                    INDEX idx_sub_customer (customer_id),
                    INDEX idx_sub_status (status)
                )
            ");
        } catch (Exception $e2) {}
    }

    try {
        // 4. booking_components table and price_at_booking
        $db->exec("
            CREATE TABLE IF NOT EXISTS booking_components (
                booking_component_id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id           INT NOT NULL,
                component_id         INT NOT NULL,
                price_at_booking     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bc_booking (booking_id),
                INDEX idx_bc_component (component_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $chkCol = $db->query("SHOW COLUMNS FROM booking_components LIKE 'price_at_booking'")->fetch();
        if (!$chkCol) {
            $db->exec("ALTER TABLE booking_components ADD COLUMN price_at_booking DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER component_id");
        }
    } catch (Exception $e) {}

    try {
        // 5. reviews table
        $db->exec("
            CREATE TABLE IF NOT EXISTS reviews (
                review_id       INT AUTO_INCREMENT PRIMARY KEY,
                booking_id      INT NOT NULL,
                customer_id     INT NOT NULL,
                rating          TINYINT UNSIGNED NOT NULL DEFAULT 5,
                service_rating  TINYINT UNSIGNED NULL,
                venue_rating    TINYINT UNSIGNED NULL,
                food_rating     TINYINT UNSIGNED NULL,
                review_text     TEXT NOT NULL,
                would_recommend TINYINT(1) NOT NULL DEFAULT 1,
                status          ENUM('published','hidden') NOT NULL DEFAULT 'published',
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_review_booking (booking_id),
                INDEX idx_review_customer (customer_id),
                INDEX idx_review_rating (rating)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS reviews (
                    review_id       INT AUTO_INCREMENT PRIMARY KEY,
                    booking_id      INT NOT NULL,
                    customer_id     INT NOT NULL,
                    rating          TINYINT UNSIGNED NOT NULL DEFAULT 5,
                    service_rating  TINYINT UNSIGNED NULL,
                    venue_rating    TINYINT UNSIGNED NULL,
                    food_rating     TINYINT UNSIGNED NULL,
                    review_text     TEXT NOT NULL,
                    would_recommend TINYINT(1) NOT NULL DEFAULT 1,
                    status          ENUM('published','hidden') NOT NULL DEFAULT 'published',
                    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_review_booking (booking_id),
                    INDEX idx_review_customer (customer_id),
                    INDEX idx_review_rating (rating)
                )
            ");
        } catch (Exception $e2) {}
    }

    try {
        // 6. email_logs table for transactional mail auditing
        $db->exec("
            CREATE TABLE IF NOT EXISTS email_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_email VARCHAR(191) NOT NULL,
                recipient_name VARCHAR(191) NULL,
                subject VARCHAR(255) NOT NULL,
                body_preview TEXT NULL,
                status ENUM('sent', 'simulated', 'failed') NOT NULL DEFAULT 'sent',
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_recipient (recipient_email),
                INDEX idx_email_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS email_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    recipient_email VARCHAR(191) NOT NULL,
                    recipient_name VARCHAR(191) NULL,
                    subject VARCHAR(255) NOT NULL,
                    body_preview TEXT NULL,
                    status ENUM('sent', 'simulated', 'failed') NOT NULL DEFAULT 'sent',
                    error_message TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } catch (Exception $e2) {}
    }

    $v4Done = true;
}

// Auto-run schema check
ensureV4Schema();

// -----------------------------------------------
//  Notification Helpers
// -----------------------------------------------
function createNotification(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$userId, $title, $message, $type, $link]);
    } catch (Exception $e) {
        return false;
    }
}

function notifyAdminsAndStaff(string $title, string $message, string $type = 'info', ?string $link = null): void {
    try {
        $db = getDB();
        $recipients = $db->query("SELECT user_id FROM users WHERE role IN ('admin', 'staff', 'cashier') AND status = 'active'")->fetchAll();
        foreach ($recipients as $r) {
            createNotification((int)$r['user_id'], $title, $message, $type, $link);
        }
    } catch (Exception $e) {}
}

function getUserUnreadNotificationsCount(int $userId): int {
    if ($userId <= 0) return 0;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function getUserNotifications(int $userId, int $limit = 10): array {
    if ($userId <= 0) return [];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT " . (int)$limit);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function markNotificationsRead(int $userId): bool {
    if ($userId <= 0) return false;
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        return $stmt->execute([$userId]);
    } catch (Exception $e) {
        return false;
    }
}

// -----------------------------------------------
//  Booking Components (Add-ons) Helpers
// -----------------------------------------------
function getBookingSelectedComponents(int $bookingId): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT bc.*, pc.name, pc.category, pc.description, bc.price_at_booking AS price
            FROM booking_components bc
            JOIN package_components pc ON bc.component_id = pc.component_id
            WHERE bc.booking_id = ?
            ORDER BY pc.category, pc.name
        ");
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

