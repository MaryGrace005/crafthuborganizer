<?php
// ============================================================
//  Reports Data API — CraftHub Organizer
//  Returns JSON data for the reports charts and stats
// ============================================================
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Security: admin only
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');
$db = getDB();

// ── Period calculation ─────────────────────────────────────────────
$period   = $_GET['period'] ?? 'monthly';
$dateFrom = $_GET['from']   ?? '';
$dateTo   = $_GET['to']     ?? '';

if (empty($dateFrom) || empty($dateTo)) {
    switch ($period) {
        case 'weekly':
            $dateFrom = date('Y-m-d', strtotime('monday this week'));
            $dateTo   = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'yearly':
            $dateFrom = date('Y-01-01');
            $dateTo   = date('Y-12-31');
            break;
        case 'monthly':
        default:
            $dateFrom = date('Y-m-01');
            $dateTo   = date('Y-m-d');
            break;
    }
}

// ── Summary Stats ──────────────────────────────────────────────────
$revRow = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?");
$revRow->execute([$dateFrom, $dateTo]);
$totalRevenue = (float)$revRow->fetchColumn();

$bookRow = $db->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) BETWEEN ? AND ? AND status != 'Cancelled'");
$bookRow->execute([$dateFrom, $dateTo]);
$totalBookings = (int)$bookRow->fetchColumn();

$custRow = $db->prepare("SELECT COUNT(*) FROM users WHERE role='customer' AND DATE(created_at) BETWEEN ? AND ?");
$custRow->execute([$dateFrom, $dateTo]);
$newCustomers = (int)$custRow->fetchColumn();

$avgBooking = $totalBookings > 0 ? round($totalRevenue / $totalBookings, 2) : 0;

// ── Revenue by Period ──────────────────────────────────────────────
// Group label and format based on period
if ($period === 'weekly') {
    $groupFmt = '%a';   // Mon, Tue...
    $groupSql = "DATE(payment_date)";
    $labelFmt = 'D';    // PHP date: 3-char weekday
} elseif ($period === 'yearly') {
    $groupFmt = '%b';   // Jan, Feb...
    $groupSql = "DATE_FORMAT(payment_date, '%Y-%m-01')";
    $labelFmt = 'M';
} else {
    $groupFmt = '%d';   // Day numbers
    $groupSql = "DATE(payment_date)";
    $labelFmt = 'd';
}

$revByPeriod = $db->prepare("
    SELECT DATE(payment_date) AS day, COALESCE(SUM(amount_paid),0) AS rev
    FROM payments
    WHERE DATE(payment_date) BETWEEN ? AND ?
    GROUP BY DATE(payment_date)
    ORDER BY day ASC
");
$revByPeriod->execute([$dateFrom, $dateTo]);
$revRows = $revByPeriod->fetchAll();

// Fill in all days in the range
$revenueLabels = [];
$revenueData   = [];
$revMap = [];
foreach ($revRows as $r) {
    $revMap[$r['day']] = (float)$r['rev'];
}

$current = strtotime($dateFrom);
$end     = strtotime($dateTo);
while ($current <= $end) {
    $key = date('Y-m-d', $current);
    if ($period === 'yearly') {
        $label = date('M', $current);
        $current = strtotime('+1 month', $current);
    } elseif ($period === 'weekly') {
        $label = date('D', $current);
        $current = strtotime('+1 day', $current);
    } else {
        $label = date('d', $current);
        $current = strtotime('+1 day', $current);
    }

    if (!in_array($label, $revenueLabels)) {
        $revenueLabels[] = $label;
        $revenueData[]   = 0;
    }
    $idx = array_search($label, $revenueLabels);
    $revenueData[$idx] += ($revMap[$key] ?? 0);
}

// ── Bookings by Period ─────────────────────────────────────────────
$bkByPeriod = $db->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM bookings
    WHERE DATE(created_at) BETWEEN ? AND ? AND status != 'Cancelled'
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$bkByPeriod->execute([$dateFrom, $dateTo]);
$bkRows = $bkByPeriod->fetchAll();
$bkMap = [];
foreach ($bkRows as $r) {
    $bkMap[$r['day']] = (int)$r['cnt'];
}

$bookingLabels = [];
$bookingData   = [];
$current = strtotime($dateFrom);
$end     = strtotime($dateTo);
while ($current <= $end) {
    $key = date('Y-m-d', $current);
    $label = ($period === 'yearly') ? date('M', $current) : (($period === 'weekly') ? date('D', $current) : date('d', $current));
    $current = ($period === 'yearly') ? strtotime('+1 month', $current) : strtotime('+1 day', $current);

    if (!in_array($label, $bookingLabels)) {
        $bookingLabels[] = $label;
        $bookingData[]   = 0;
    }
    $idx = array_search($label, $bookingLabels);
    $bookingData[$idx] += ($bkMap[$key] ?? 0);
}

// ── Top Packages (Doughnut) ────────────────────────────────────────
$topPkgStmt = $db->prepare("
    SELECT p.package_name AS name, COUNT(b.booking_id) AS cnt
    FROM bookings b
    JOIN packages p ON b.package_id = p.package_id
    WHERE DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY p.package_id ORDER BY cnt DESC LIMIT 6
");
$topPkgStmt->execute([$dateFrom, $dateTo]);
$topPackages = $topPkgStmt->fetchAll();

// ── Payment Methods (Pie) ──────────────────────────────────────────
$pmStmt = $db->prepare("
    SELECT COALESCE(payment_method,'cash') AS method, COALESCE(SUM(amount_paid),0) AS total
    FROM payments
    WHERE DATE(payment_date) BETWEEN ? AND ?
    GROUP BY payment_method
");
$pmStmt->execute([$dateFrom, $dateTo]);
$payMethods = $pmStmt->fetchAll();

// ── Recent Payments detail ─────────────────────────────────────────
$recentPay = $db->prepare("
    SELECT py.amount_paid, py.payment_method, py.payment_date,
           u.name AS cashier, c.name AS customer,
           b.booking_reference
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN users u ON py.cashier_id = u.user_id
    JOIN users c ON b.customer_id = c.user_id
    WHERE DATE(py.payment_date) BETWEEN ? AND ?
    ORDER BY py.payment_date DESC
    LIMIT 20
");
$recentPay->execute([$dateFrom, $dateTo]);
$recentPayments = $recentPay->fetchAll();

// ── Response ───────────────────────────────────────────────────────
echo json_encode([
    'period'    => $period,
    'dateFrom'  => $dateFrom,
    'dateTo'    => $dateTo,
    'stats' => [
        'totalRevenue'  => $totalRevenue,
        'totalBookings' => $totalBookings,
        'newCustomers'  => $newCustomers,
        'avgBooking'    => $avgBooking,
    ],
    'revenueChart' => [
        'labels' => $revenueLabels,
        'data'   => $revenueData,
    ],
    'bookingsChart' => [
        'labels' => $bookingLabels,
        'data'   => $bookingData,
    ],
    'topPackages' => [
        'labels' => array_column($topPackages, 'name'),
        'data'   => array_map('intval', array_column($topPackages, 'cnt')),
    ],
    'paymentMethods' => [
        'labels' => array_map(fn($r) => ucwords(str_replace('_',' ',$r['method'])), $payMethods),
        'data'   => array_map('floatval', array_column($payMethods, 'total')),
    ],
    'recentPayments' => $recentPayments,
    'generated_at'  => date('Y-m-d H:i:s'),
]);
