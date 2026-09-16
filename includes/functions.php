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
    $stmt = $db->query("SELECT package_id AS id, package_id, package_name AS name, package_name, base_price AS price, base_price, description, status FROM packages WHERE status = 'active' ORDER BY base_price ASC");
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

