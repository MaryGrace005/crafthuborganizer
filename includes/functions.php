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
        'active'      => 'success',
        'available'   => 'success',
        'confirmed'   => 'success',
        'completed'   => 'info',
        'paid'        => 'success',
        'pending'     => 'warning',
        'partial'     => 'warning',
        'unpaid'      => 'danger',
        'inactive'    => 'secondary',
        'cancelled'   => 'danger',
        'maintenance' => 'warning',
        'banned'      => 'danger',
    ];
    $class = $map[strtolower($statusStr)] ?? 'secondary';
    return "<span class=\"badge badge-{$class}\">" . ucfirst($statusStr) . "</span>";
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
//  Update Booking Payment Status
// -----------------------------------------------
function updateBookingPaymentStatus(int $bookingId): void {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT total_amount,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS amount_paid
        FROM bookings b WHERE b.booking_id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) return;

    $total = (float)$booking['total_amount'];
    $paid  = (float)$booking['amount_paid'];

    if ($paid <= 0) {
        $status = 'Pending';
    } elseif ($paid >= $total) {
        $status = 'Paid';
    } else {
        $status = 'Confirmed';
    }

    $update = $db->prepare("UPDATE bookings SET status = ? WHERE booking_id = ?");
    $update->execute([$status, $bookingId]);
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
    } elseif ($role === 'cashier') {
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
