<?php
// ============================================================
//  Invoice / Statement of Account — CraftHub Organizer
//  Accessible by customer (own bookings), staff, cashier, admin
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit();
}

$db        = getDB();
$user      = getCurrentUser();
$userId    = (int)($user['user_id'] ?? $user['id'] ?? 0);
$role      = $_SESSION['user_role'] ?? 'customer';
$bookingId = (int)($_GET['id'] ?? 0);

if (!$bookingId) {
    die('<h2>Invalid booking ID.</h2>');
}

// Fetch booking — customers can only see their own
$params = [$bookingId];
$ownerFilter = '';
if ($role === 'customer') {
    $ownerFilter = ' AND b.customer_id = ?';
    $params[] = $userId;
}

$stmt = $db->prepare("
    SELECT b.*, b.booking_id AS id,
           COALESCE(b.payment_plan,'full')            AS payment_plan,
           COALESCE(b.discount_amount,0)              AS discount_amount,
           COALESCE(b.downpayment_amount,0)           AS downpayment_amount,
           u.name AS customer_name, u.email AS customer_email, u.contact_no AS customer_phone, u.address AS customer_address,
           p.package_name, p.base_price,
           v.venue_name, v.location AS venue_location, v.capacity AS venue_capacity
    FROM bookings b
    JOIN users u ON b.customer_id = u.user_id
    JOIN packages p ON b.package_id = p.package_id
    LEFT JOIN venues v ON b.venue_id = v.venue_id
    WHERE b.booking_id = ? {$ownerFilter}
");
$stmt->execute($params);
$booking = $stmt->fetch();

if (!$booking) {
    die('<h2>Booking not found or access denied.</h2>');
}

// Get add-on components
$addons = getBookingSelectedComponents($bookingId);

// Get payment records
$payments = $db->prepare("
    SELECT py.*, c.name AS cashier_name
    FROM payments py
    LEFT JOIN users c ON py.cashier_id = c.user_id
    WHERE py.booking_id = ?
    ORDER BY py.payment_date ASC
");
$payments->execute([$bookingId]);
$payments = $payments->fetchAll();

$totalPaid = array_sum(array_column($payments, 'amount_paid'));
$balance   = max(0.0, (float)$booking['total_amount'] - $totalPaid);
$ref       = getBookingRef($booking);
$dueDate   = getBookingPaymentDueDate($booking);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($ref) ?> | <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Outfit', sans-serif;
    background: #f4f6fb;
    color: #1a1a2e;
    padding: 24px;
}
.invoice-wrapper {
    max-width: 820px;
    margin: 0 auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 8px 40px rgba(0,0,0,0.1);
    overflow: hidden;
}
.invoice-header {
    background: linear-gradient(135deg, #0b0b1f 0%, #1a1a3e 50%, #0d1b4b 100%);
    color: #fff;
    padding: 32px 36px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
}
.brand-name {
    font-size: 1.8rem;
    font-weight: 800;
    background: linear-gradient(90deg, #4ecdc4, #f5a623);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.brand-tagline { font-size: 0.78rem; color: rgba(255,255,255,0.6); margin-top: 4px; }
.invoice-meta { text-align: right; }
.invoice-ref { font-size: 1.2rem; font-weight: 700; color: #4ecdc4; }
.status-badge {
    display: inline-block; padding: 4px 12px; border-radius: 20px;
    font-size: 0.78rem; font-weight: 700; margin-top: 6px;
}
.invoice-body { padding: 32px 36px; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px; }
.info-box { background: #f8f9fc; border-radius: 10px; padding: 16px 18px; }
.info-label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 6px; font-weight: 600; }
.info-value { font-size: 0.9rem; font-weight: 600; color: #1a1a2e; line-height: 1.5; }
.section-title {
    font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em;
    color: #888; font-weight: 700; margin-bottom: 12px; padding-bottom: 8px;
    border-bottom: 2px solid #f0f0f0;
}
table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
th { background: #f0f4ff; padding: 10px 14px; text-align: left; font-size: 0.78rem; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: 0.05em; }
td { padding: 11px 14px; font-size: 0.88rem; color: #333; border-bottom: 1px solid #f0f0f0; }
tr:last-child td { border-bottom: none; }
.amount-right { text-align: right; font-weight: 600; }
.total-row td { font-weight: 800; font-size: 1rem; border-top: 2px solid #e8e8f0; padding-top: 14px; }
.total-row .amount-right { color: #0d1b4b; }
.balance-row td { color: #e94560; font-size: 1.1rem; }
.payment-table th { background: #f0fff4; }
.summary-box {
    background: linear-gradient(135deg, #0b0b1f, #1a1a3e);
    color: #fff;
    border-radius: 12px;
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}
.summary-col { text-align: center; }
.summary-num { font-size: 1.3rem; font-weight: 800; }
.summary-lbl { font-size: 0.72rem; color: rgba(255,255,255,0.6); margin-top: 3px; }
.footer-note { text-align: center; font-size: 0.78rem; color: #999; padding: 16px; border-top: 1px solid #f0f0f0; line-height: 1.7; }
.print-btn {
    display: block; margin: 16px auto; padding: 12px 32px;
    background: linear-gradient(135deg, #4ecdc4, #3bb5ac);
    color: #fff; border: none; border-radius: 10px;
    font-size: 0.95rem; font-weight: 700; cursor: pointer; font-family: 'Outfit', sans-serif;
    box-shadow: 0 4px 15px rgba(78,205,196,0.3);
}
.back-btn {
    display: inline-block; padding: 10px 22px;
    background: rgba(0,0,0,0.06); color: #555; border-radius: 10px;
    font-size: 0.85rem; font-weight: 600; text-decoration: none;
    margin-bottom: 16px; margin-right: 8px;
}
@media print {
    body { background: white; padding: 0; }
    .invoice-wrapper { box-shadow: none; border-radius: 0; }
    .no-print { display: none !important; }
    .print-btn { display: none; }
    .back-btn { display: none; }
}
</style>
</head>
<body>

<div class="no-print" style="max-width:820px;margin:0 auto 16px;">
    <a href="javascript:history.back()" class="back-btn">
        ← Back
    </a>
    <button onclick="window.print()" class="print-btn" style="display:inline-block;margin:0;">
        🖨️ Print / Save as PDF
    </button>
</div>

<div class="invoice-wrapper">
    <!-- Header -->
    <div class="invoice-header">
        <div>
            <div class="brand-name">🎨 <?= APP_NAME ?></div>
            <div class="brand-tagline">Your Craft Event Specialist</div>
            <div style="margin-top:10px;font-size:0.8rem;color:rgba(255,255,255,0.55);">
                crafthub@email.com &nbsp;|&nbsp; Tel: +63 912 345 6789
            </div>
        </div>
        <div class="invoice-meta">
            <div style="font-size:0.75rem;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:0.08em;">Statement of Account</div>
            <div class="invoice-ref"><?= htmlspecialchars($ref) ?></div>
            <div style="margin-top:6px;">
                <?php
                $statusColors = ['Pending'=>'#f5a623','Confirmed'=>'#4ecdc4','Paid'=>'#27ae60','Completed'=>'#27ae60','Cancelled'=>'#e94560'];
                $sColor = $statusColors[$booking['status']] ?? '#aaa';
                ?>
                <span class="status-badge" style="background:<?= $sColor ?>22;color:<?= $sColor ?>;border:1px solid <?= $sColor ?>55;">
                    <?= htmlspecialchars($booking['status']) ?>
                </span>
            </div>
            <div style="margin-top:8px;font-size:0.78rem;color:rgba(255,255,255,0.55);">
                Booked: <?= date('M j, Y', strtotime($booking['created_at'])) ?>
            </div>
        </div>
    </div>

    <div class="invoice-body">

        <!-- Customer & Event Info -->
        <div class="info-grid">
            <div class="info-box">
                <div class="info-label">Billed To</div>
                <div class="info-value">
                    <strong><?= htmlspecialchars($booking['customer_name']) ?></strong><br>
                    <?= htmlspecialchars($booking['customer_email']) ?><br>
                    <?= htmlspecialchars($booking['customer_phone'] ?? '—') ?><br>
                    <span style="font-size:0.82rem;color:#777;"><?= htmlspecialchars($booking['customer_address'] ?? '') ?></span>
                </div>
            </div>
            <div class="info-box">
                <div class="info-label">Event Details</div>
                <div class="info-value">
                    <strong><?= htmlspecialchars($booking['event_type']) ?></strong><br>
                    📅 <?= formatDate($booking['event_date']) ?> at <?= date('g:i A', strtotime($booking['event_time'] ?? '09:00:00')) ?><br>
                    <?php if ($booking['venue_name']): ?>
                    📍 <?= htmlspecialchars($booking['venue_name']) ?><br>
                    <span style="font-size:0.82rem;color:#777;"><?= htmlspecialchars($booking['venue_location'] ?? '') ?></span>
                    <?php endif; ?>
                    👥 <?= (int)($booking['guest_count'] ?? 0) ?> guests
                </div>
            </div>
        </div>

        <!-- Payment Due Date + Notes -->
        <?php if ($balance > 0.01): ?>
        <div style="background:#fff8e8;border:1px solid #f5a62355;border-left:4px solid #f5a623;border-radius:10px;padding:14px 18px;margin-bottom:24px;font-size:0.85rem;">
            <strong style="color:#c67c00;">⚠ Payment Due:</strong>
            <?= date('F j, Y', strtotime($dueDate)) ?>
            — Outstanding balance of <strong style="color:#e94560;">₱<?= number_format($balance, 2) ?></strong> must be settled before the event date.
        </div>
        <?php endif; ?>

        <!-- Service Breakdown -->
        <div class="section-title">Package & Services</div>
        <table>
            <thead>
                <tr>
                    <th style="width:50%;">Description</th>
                    <th>Category</th>
                    <th class="amount-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($booking['package_name']) ?></strong>
                        <div style="font-size:0.78rem;color:#888;margin-top:2px;">Base Package</div>
                    </td>
                    <td><span style="background:#e8eef8;color:#3a5aad;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;">Package</span></td>
                    <td class="amount-right">₱<?= number_format((float)$booking['base_price'], 2) ?></td>
                </tr>
                <?php foreach ($addons as $addon): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($addon['name']) ?>
                        <?php if (!empty($addon['description'])): ?>
                        <div style="font-size:0.78rem;color:#888;margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($addon['description'], 0, 80, '...')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span style="background:#f0f8f0;color:#27ae60;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;"><?= ucfirst($addon['category']) ?></span></td>
                    <td class="amount-right">₱<?= number_format((float)$addon['price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!empty($booking['notes'])): ?>
                <tr>
                    <td colspan="3" style="font-style:italic;color:#888;font-size:0.82rem;padding-top:8px;">
                        📝 Notes: <?= htmlspecialchars($booking['notes']) ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php
                    $discAmt    = (float)($booking['discount_amount'] ?? 0);
                    $plan       = $booking['payment_plan'] ?? 'full';
                    $baseP      = (float)$booking['base_price'];
                    $addonTotal = array_sum(array_column($addons, 'price'));
                    $subtotal   = $baseP + $addonTotal;
                    $discTot    = (float)$booking['total_amount'];
                    // Recalculate discount pct for display
                    $discPctDisplay = ($subtotal > 0 && $discAmt > 0) ? round($discAmt/$subtotal*100, 2) : 0;
                ?>
                <?php if ($discAmt > 0): ?>
                <tr style="background:#f0fdf8;">
                    <td colspan="2" style="color:#147a67;font-size:0.82rem;">
                        <span style="background:#d0faf0;color:#0a7c63;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;margin-right:6px;">
                            <?= $plan === 'full' ? 'Full Payment' : 'Downpayment' ?>
                        </span>
                        Discount Applied (<?= $discPctDisplay ?>%)
                    </td>
                    <td class="amount-right" style="color:#27ae60;">−₱<?= number_format($discAmt, 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td colspan="2"><strong>Total Amount (after discount)</strong></td>
                    <td class="amount-right">₱<?= number_format($discTot, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Payment Summary -->
        <div class="summary-box">
            <div class="summary-col">
                <div class="summary-num" style="color:#4ecdc4;">₱<?= number_format((float)$booking['total_amount'], 2) ?></div>
                <div class="summary-lbl">Total</div>
            </div>
            <div style="color:rgba(255,255,255,0.3);font-size:1.5rem;">−</div>
            <div class="summary-col">
                <div class="summary-num" style="color:#27ae60;">₱<?= number_format($totalPaid, 2) ?></div>
                <div class="summary-lbl">Total Paid</div>
            </div>
            <div style="color:rgba(255,255,255,0.3);font-size:1.5rem;">=</div>
            <div class="summary-col">
                <div class="summary-num" style="color:<?= $balance > 0 ? '#e94560' : '#27ae60' ?>;">
                    ₱<?= number_format($balance, 2) ?>
                </div>
                <div class="summary-lbl"><?= $balance > 0 ? 'Outstanding Balance' : '✅ Fully Paid' ?></div>
            </div>
        </div>

        <!-- Payment History -->
        <?php if (!empty($payments)): ?>
        <div class="section-title">Payment History</div>
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>OR Number</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Received By</th>
                    <th class="amount-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $pay): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= date('M j, Y', strtotime($pay['payment_date'])) ?></td>
                    <td style="font-family:monospace;font-size:0.83rem;font-weight:700;color:#0d1b4b;"><?= htmlspecialchars($pay['or_number']) ?></td>
                    <td><?= ucfirst($pay['payment_type']) ?></td>
                    <td><?= ucwords(str_replace('_',' ',$pay['payment_method'] ?? 'Cash')) ?></td>
                    <td style="font-size:0.8rem;color:#666;"><?= htmlspecialchars($pay['reference_no'] ?? '—') ?></td>
                    <td style="font-size:0.82rem;"><?= htmlspecialchars($pay['cashier_name'] ?? 'Staff') ?></td>
                    <td class="amount-right" style="color:#27ae60;font-weight:700;">₱<?= number_format($pay['amount_paid'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    </div>

    <div class="footer-note">
        This document is an official Statement of Account from <strong><?= APP_NAME ?></strong>.<br>
        For inquiries, contact us at crafthub@email.com or call +63 912 345 6789.<br>
        <span style="font-size:0.72rem;color:#ccc;">Generated: <?= date('F j, Y g:i A') ?></span>
    </div>
</div>

</body>
</html>
