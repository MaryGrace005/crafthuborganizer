<?php
// ============================================================
//  Staff / Cashier Reports Data API — CraftHub Organizer
//  Returns JSON analytics for staff reports page (auto-poll)
// ============================================================
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Security: staff and cashier only
$role = $_SESSION['user_role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['staff', 'cashier', 'admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');
$db = getDB();

// ── Period ─────────────────────────────────────────────────
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
        case 'today':
            $dateFrom = date('Y-m-d');
            $dateTo   = date('Y-m-d');
            break;
        case 'monthly':
        default:
            $dateFrom = date('Y-m-01');
            $dateTo   = date('Y-m-d');
            break;
    }
}

// ── Summary Stats ───────────────────────────────────────────
// Total revenue collected in period
$revRow = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?");
$revRow->execute([$dateFrom, $dateTo]);
$totalRevenue = (float)$revRow->fetchColumn();

// Today's revenue
$todayRev = $db->query("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE DATE(payment_date) = CURDATE()")->fetchColumn();

// Total bookings
$bookRow = $db->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled'");
$bookRow->execute([$dateFrom, $dateTo]);
$totalBookings = (int)$bookRow->fetchColumn();

// Cancelled bookings
$cancelRow = $db->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) BETWEEN ? AND ? AND LOWER(status) = 'cancelled'");
$cancelRow->execute([$dateFrom, $dateTo]);
$cancelledBookings = (int)$cancelRow->fetchColumn();

// Outstanding balance (unpaid / partial)
$balRow = $db->query("
    SELECT COALESCE(SUM(b.total_amount - COALESCE(paid.p,0)), 0)
    FROM bookings b
    LEFT JOIN (SELECT booking_id, SUM(amount_paid) AS p FROM payments GROUP BY booking_id) paid ON b.booking_id = paid.booking_id
    WHERE LOWER(b.status) NOT IN ('cancelled','completed')
      AND b.total_amount > COALESCE(paid.p, 0)
");
$outstandingBalance = (float)$balRow->fetchColumn();

// Today's transactions count
$txRow = $db->query("SELECT COUNT(*) FROM payments WHERE DATE(payment_date) = CURDATE()")->fetchColumn();
$todayTransactions = (int)$txRow;

// ── Revenue by Period ───────────────────────────────────────
$revByPeriod = $db->prepare("
    SELECT DATE(payment_date) AS day, COALESCE(SUM(amount_paid),0) AS rev
    FROM payments
    WHERE DATE(payment_date) BETWEEN ? AND ?
    GROUP BY DATE(payment_date)
    ORDER BY day ASC
");
$revByPeriod->execute([$dateFrom, $dateTo]);
$revRows = $revByPeriod->fetchAll();
$revMap = [];
foreach ($revRows as $r) { $revMap[$r['day']] = (float)$r['rev']; }

$revenueLabels = [];
$revenueData   = [];
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
        $label = date('d M', $current);
        $current = strtotime('+1 day', $current);
    }
    if (!in_array($label, $revenueLabels)) {
        $revenueLabels[] = $label;
        $revenueData[]   = 0;
    }
    $idx = array_search($label, $revenueLabels);
    $revenueData[$idx] += ($revMap[$key] ?? 0);
}

// ── Bookings by Period ──────────────────────────────────────
$bkByPeriod = $db->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM bookings
    WHERE DATE(created_at) BETWEEN ? AND ? AND LOWER(status) != 'cancelled'
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$bkByPeriod->execute([$dateFrom, $dateTo]);
$bkRows = $bkByPeriod->fetchAll();
$bkMap = [];
foreach ($bkRows as $r) { $bkMap[$r['day']] = (int)$r['cnt']; }

$bookingLabels = [];
$bookingData   = [];
$current = strtotime($dateFrom);
while ($current <= $end) {
    $key = date('Y-m-d', $current);
    $label = ($period === 'yearly') ? date('M', $current) : (($period === 'weekly') ? date('D', $current) : date('d M', $current));
    $current = ($period === 'yearly') ? strtotime('+1 month', $current) : strtotime('+1 day', $current);
    if (!in_array($label, $bookingLabels)) {
        $bookingLabels[] = $label;
        $bookingData[]   = 0;
    }
    $idx = array_search($label, $bookingLabels);
    $bookingData[$idx] += ($bkMap[$key] ?? 0);
}

// ── Top Packages (Doughnut) ─────────────────────────────────
$topPkgStmt = $db->prepare("
    SELECT p.package_name AS name, COUNT(b.booking_id) AS cnt
    FROM bookings b
    JOIN packages p ON b.package_id = p.package_id
    WHERE DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY p.package_id ORDER BY cnt DESC LIMIT 6
");
$topPkgStmt->execute([$dateFrom, $dateTo]);
$topPackages = $topPkgStmt->fetchAll();

// ── Payment Methods (Pie) ───────────────────────────────────
$pmStmt = $db->prepare("
    SELECT COALESCE(payment_method,'cash') AS method, COALESCE(SUM(amount_paid),0) AS total
    FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?
    GROUP BY payment_method
");
$pmStmt->execute([$dateFrom, $dateTo]);
$payMethods = $pmStmt->fetchAll();

// ── Payment Types (Donut) ────────────────────────────────────
$ptStmt = $db->prepare("
    SELECT COALESCE(payment_type,'full') AS ptype, COALESCE(SUM(amount_paid),0) AS total
    FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?
    GROUP BY payment_type
");
$ptStmt->execute([$dateFrom, $dateTo]);
$payTypes = $ptStmt->fetchAll();

// ── Recent Payments ─────────────────────────────────────────
$recentPay = $db->prepare("
    SELECT py.payment_id, py.amount_paid, py.payment_method, py.payment_date, py.payment_type,
           py.or_number, py.notes, py.reference_no,
           u.name AS cashier,
           c.name AS customer,
           b.booking_id,
           COALESCE(b.booking_reference, CONCAT('BK-', YEAR(b.created_at), '-', LPAD(b.booking_id, 5, '0'))) AS booking_ref,
           p.package_name,
           b.event_date,
           b.status AS booking_status
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN packages p ON b.package_id = p.package_id
    JOIN users u ON py.cashier_id = u.user_id
    JOIN users c ON b.customer_id = c.user_id
    WHERE DATE(py.payment_date) BETWEEN ? AND ?
    ORDER BY py.payment_date DESC
    LIMIT 20
");
$recentPay->execute([$dateFrom, $dateTo]);
$recentPayments = $recentPay->fetchAll();

// ── Response ────────────────────────────────────────────────
echo json_encode([
    'period'              => $period,
    'dateFrom'            => $dateFrom,
    'dateTo'              => $dateTo,
    'stats' => [
        'totalRevenue'        => $totalRevenue,
        'todayRevenue'        => (float)$todayRev,
        'totalBookings'       => $totalBookings,
        'cancelledBookings'   => $cancelledBookings,
        'outstandingBalance'  => $outstandingBalance,
        'todayTransactions'   => $todayTransactions,
    ],
    'revenueChart'        => ['labels' => $revenueLabels, 'data' => $revenueData],
    'bookingsChart'       => ['labels' => $bookingLabels, 'data' => $bookingData],
    'topPackages'         => ['labels' => array_column($topPackages,'name'), 'data' => array_map('intval', array_column($topPackages,'cnt'))],
    'paymentMethods'      => ['labels' => array_map(fn($r) => ucwords(str_replace('_',' ',$r['method'])), $payMethods), 'data' => array_map('floatval', array_column($payMethods,'total'))],
    'paymentTypes'        => ['labels' => array_map(fn($r) => ucwords(str_replace('_',' ',$r['ptype'])), $payTypes), 'data' => array_map('floatval', array_column($payTypes,'total'))],
    'recentPayments'      => $recentPayments,
    'generated_at'        => date('Y-m-d H:i:s'),
]);
