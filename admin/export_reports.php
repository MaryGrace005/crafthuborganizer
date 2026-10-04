<?php
// ============================================================
//  Export Reports (CSV) — CraftHub Organizer
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['admin', 'staff', 'cashier']);

$db = getDB();

$type     = sanitize($_GET['type'] ?? 'bookings');
$dateFrom = sanitize($_GET['from'] ?? '');
$dateTo   = sanitize($_GET['to'] ?? '');

$filename = "crafthub_{$type}_report_" . date('Y-m-d_His') . ".csv";

// Output headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM so Excel opens it with proper characters and currency symbols
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

if ($type === 'payments' || $type === 'revenue') {
    // CSV Header
    fputcsv($output, [
        'OR Number',
        'Payment Date',
        'Booking Ref',
        'Customer Name',
        'Customer Email',
        'Package Name',
        'Amount Paid (PHP)',
        'Payment Method',
        'Reference No',
        'Cashier / Processed By'
    ]);

    $query = "
        SELECT py.receipt_number, py.payment_date, py.amount_paid, py.payment_method, py.reference_no,
               b.booking_reference, b.booking_id,
               u.name AS customer_name, u.email AS customer_email,
               p.package_name,
               c.name AS cashier_name
        FROM payments py
        JOIN bookings b ON py.booking_id = b.booking_id
        JOIN users u ON b.customer_id = u.user_id
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN users c ON py.cashier_id = c.user_id
        WHERE 1=1
    ";
    $params = [];
    if (!empty($dateFrom)) {
        $query .= " AND DATE(py.payment_date) >= ?";
        $params[] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $query .= " AND DATE(py.payment_date) <= ?";
        $params[] = $dateTo;
    }
    $query .= " ORDER BY py.payment_date DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $total = 0;
    while ($row = $stmt->fetch()) {
        $total += (float)$row['amount_paid'];
        $ref = !empty($row['booking_reference']) ? $row['booking_reference'] : 'BK-' . str_pad($row['booking_id'], 5, '0', STR_PAD_LEFT);
        fputcsv($output, [
            $row['receipt_number'] ?: ('OR-' . str_pad($row['booking_id'], 5, '0', STR_PAD_LEFT)),
            $row['payment_date'],
            $ref,
            $row['customer_name'],
            $row['customer_email'],
            $row['package_name'],
            number_format((float)$row['amount_paid'], 2, '.', ''),
            strtoupper($row['payment_method']),
            $row['reference_no'] ?: 'N/A',
            $row['cashier_name'] ?: 'System / Online'
        ]);
    }

    // Summary line
    fputcsv($output, []);
    fputcsv($output, ['', '', '', '', '', 'TOTAL COLLECTED:', number_format($total, 2, '.', ''), '', '', '']);

} else {
    // Default: Bookings CSV
    fputcsv($output, [
        'Booking Ref',
        'Booked Date',
        'Event Date',
        'Customer Name',
        'Customer Email',
        'Customer Phone',
        'Package Name',
        'Venue',
        'Status',
        'Total Amount (PHP)',
        'Total Paid (PHP)',
        'Remaining Balance (PHP)'
    ]);

    $query = "
        SELECT b.*,
               u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
               p.package_name,
               v.venue_name,
               (SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE booking_id = b.booking_id) AS calc_paid
        FROM bookings b
        JOIN users u ON b.customer_id = u.user_id
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id
        WHERE 1=1
    ";
    $params = [];
    if (!empty($dateFrom)) {
        $query .= " AND DATE(b.event_date) >= ?";
        $params[] = $dateFrom;
    }
    if (!empty($dateTo)) {
        $query .= " AND DATE(b.event_date) <= ?";
        $params[] = $dateTo;
    }
    $query .= " ORDER BY b.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $totAmount = 0;
    $totPaid   = 0;
    $totBal    = 0;

    while ($row = $stmt->fetch()) {
        $ref     = !empty($row['booking_reference']) ? $row['booking_reference'] : 'BK-' . str_pad($row['booking_id'], 5, '0', STR_PAD_LEFT);
        $total   = (float)$row['total_amount'];
        $paid    = (float)$row['calc_paid'];
        $bal     = max(0.0, $total - $paid);

        $totAmount += $total;
        $totPaid   += $paid;
        $totBal    += $bal;

        fputcsv($output, [
            $ref,
            $row['created_at'],
            $row['event_date'],
            $row['customer_name'],
            $row['customer_email'],
            $row['customer_phone'] ?: 'N/A',
            $row['package_name'],
            $row['venue_name'] ?: 'None Selected',
            $row['status'],
            number_format($total, 2, '.', ''),
            number_format($paid, 2, '.', ''),
            number_format($bal, 2, '.', '')
        ]);
    }

    // Summary line
    fputcsv($output, []);
    fputcsv($output, [
        '', '', '', '', '', '', '', '', 'TOTALS:',
        number_format($totAmount, 2, '.', ''),
        number_format($totPaid, 2, '.', ''),
        number_format($totBal, 2, '.', '')
    ]);
}

fclose($output);
exit();
