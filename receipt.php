<?php
// ============================================================
//  Official Payment Receipt — CraftHub Organizer
//  Standalone printable official electronic receipt
// ============================================================
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$paymentId = (int)($_GET['id'] ?? $_GET['payment_id'] ?? 0);
$user      = getCurrentUser();
$userId    = $user['user_id'] ?? $user['id'];
$db        = getDB();

if (!$paymentId) {
    setFlash('error', 'No receipt selected.');
    redirect(APP_URL . '/index.php');
}

// Fetch payment, booking, package, customer, and cashier info
$stmt = $db->prepare("
    SELECT py.*, py.payment_id AS id,
           b.booking_reference, b.total_amount, b.event_date,
           p.package_name,
           c.name AS customer_name, c.email AS customer_email, c.id_code AS customer_id_code, c.contact_no AS customer_phone, c.user_id AS customer_user_id,
           u.name AS cashier_name,
           b.status AS booking_status,
           (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS booking_total_paid
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN packages p ON b.package_id = p.package_id
    JOIN users c ON b.customer_id = c.user_id
    JOIN users u ON py.cashier_id = u.user_id
    WHERE py.payment_id = ?
");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Receipt not found.</h3>');
}

// Security: Customers can only view their own receipts; admin/staff/cashier can view all
if ($user['role'] === 'customer' && (int)$payment['customer_user_id'] !== (int)$userId) {
    die('<h3 style="color:red;font-family:sans-serif;padding:20px;">Access denied.</h3>');
}

$totalAmount  = (float)($payment['total_amount'] ?? 0);
$totalPaid    = (float)($payment['booking_total_paid'] ?? 0);
$remBalance   = max(0.0, $totalAmount - $totalPaid);
$orNumber     = !empty($payment['or_number']) ? $payment['or_number'] : ('OR-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT));
$bookingRef   = !empty($payment['booking_reference']) ? $payment['booking_reference'] : ('BK-' . str_pad($payment['booking_id'], 8, '0', STR_PAD_LEFT));
$isCancelled  = strtolower($payment['booking_status'] ?? '') === 'cancelled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Receipt #<?= htmlspecialchars($orNumber) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f0f1a;
            --bg-card: #161626;
            --accent-teal: #4ecdc4;
            --accent-gold: #f5a623;
            --accent-red: #e94560;
            --text-primary: #f0f0ff;
            --text-secondary: #a0a0c0;
            --text-muted: #6c6c8a;
        }
        body {
            background: var(--bg-dark);
            color: var(--text-primary);
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin: 0;
            padding: 40px 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .receipt-card {
            background: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            width: 100%;
            max-width: 520px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
            position: relative;
        }
        .receipt-header {
            text-align: center;
            padding: 32px 32px 20px 32px;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.15);
        }
        .brand-logo {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--accent-teal);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .receipt-badge {
            font-size: 0.76rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-top: 4px;
            font-weight: 700;
        }
        .receipt-body {
            padding: 24px 32px;
        }
        .meta-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(78, 205, 196, 0.06);
            border: 1px solid rgba(78, 205, 196, 0.2);
            padding: 14px 18px;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .meta-label {
            font-size: 0.72rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .meta-val-or {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--accent-gold);
        }
        .grid-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
            font-size: 0.86rem;
        }
        .detail-group strong {
            color: #fff;
            display: block;
            margin-top: 2px;
        }
        .table-breakdown {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 0.88rem;
        }
        .table-breakdown th {
            text-align: left;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-muted);
            font-size: 0.74rem;
            text-transform: uppercase;
        }
        .table-breakdown td {
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .footer-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px dashed rgba(255, 255, 255, 0.15);
            font-size: 0.82rem;
            color: var(--text-muted);
        }
        .action-bar {
            background: #0f0f1a;
            padding: 16px 32px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            font-size: 0.88rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-secondary {
            background: rgba(255,255,255,0.08);
            color: var(--text-primary);
        }
        .btn-secondary:hover { background: rgba(255,255,255,0.15); }
        .btn-primary {
            background: linear-gradient(135deg, #4ecdc4, #2b938b);
            color: #0f0f1a;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(78,205,196,0.4); }

        @media print {
            body { background: #fff !important; color: #000 !important; padding: 0; }
            .receipt-card { border: 1px solid #ccc; box-shadow: none; background: #fff !important; color: #000 !important; }
            .action-bar { display: none !important; }
            .brand-logo, .meta-val-or { color: #000 !important; }
            .meta-box { background: #f8f9fa !important; border-color: #ddd !important; }
            strong { color: #000 !important; }
            .cancelled-watermark { display: flex !important; }
            .cancelled-banner { display: flex !important; }
        }

        /* Cancelled styles */
        .cancelled-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(233, 69, 96, 0.15);
            border: 2px solid var(--accent-red);
            border-radius: 12px;
            padding: 12px 18px;
            margin-bottom: 18px;
            color: var(--accent-red);
            font-weight: 800;
            font-size: 0.95rem;
        }
        .cancelled-banner i { font-size: 1.1rem; }
        .receipt-body.is-cancelled {
            position: relative;
        }
        .cancelled-watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 10;
            transform: rotate(-30deg);
        }
        .cancelled-watermark span {
            font-family: 'Outfit', sans-serif;
            font-size: 4.5rem;
            font-weight: 900;
            color: rgba(233, 69, 96, 0.18);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border: 6px solid rgba(233, 69, 96, 0.18);
            padding: 8px 24px;
            border-radius: 12px;
            white-space: nowrap;
            user-select: none;
        }
    </style>
</head>
<body>

<div class="receipt-card">
    <div class="receipt-header">
        <div class="brand-logo">
            <i class="fa-solid fa-palette"></i> <?= APP_NAME ?>
        </div>
        <div class="receipt-badge">Official Payment Receipt</div>
    </div>

    <div class="receipt-body<?= $isCancelled ? ' is-cancelled' : '' ?>">

        <?php if ($isCancelled): ?>
        <!-- Cancelled banner -->
        <div class="cancelled-banner">
            <i class="fa-solid fa-ban"></i>
            <div>
                <div style="font-size:1rem;">BOOKING CANCELLED</div>
                <div style="font-size:0.78rem;font-weight:500;color:rgba(233,69,96,0.8);">This booking has been cancelled. This receipt is for record purposes only.</div>
            </div>
        </div>
        <!-- Cancelled diagonal watermark -->
        <div class="cancelled-watermark"><span>CANCELLED</span></div>
        <?php endif; ?>

        <!-- OR Number & Date -->
        <div class="meta-box">
            <div>
                <div class="meta-label">Official Receipt No.</div>
                <div class="meta-val-or"><?= htmlspecialchars($orNumber) ?></div>
            </div>
            <div style="text-align:right;">
                <div class="meta-label">Date Issued</div>
                <div style="font-weight:700;color:var(--text-primary);"><?= formatDate($payment['payment_date']) ?></div>
            </div>
        </div>

        <!-- Customer & Booking Info -->
        <div class="grid-details">
            <div class="detail-group">
                <span class="meta-label">Customer</span>
                <strong><?= htmlspecialchars($payment['customer_name']) ?></strong>
                <div style="font-size:0.78rem;color:var(--text-muted);"><?= htmlspecialchars($payment['customer_email']) ?></div>
                <?php if (!empty($payment['customer_id_code'])): ?>
                    <div style="font-size:0.78rem;color:var(--accent-teal);font-weight:700;">ID: <?= htmlspecialchars($payment['customer_id_code']) ?></div>
                <?php endif; ?>
            </div>
            <div class="detail-group">
                <span class="meta-label">Booking Reference</span>
                <strong style="color:var(--accent-teal);"><?= htmlspecialchars($bookingRef) ?></strong>
                <div style="font-size:0.8rem;color:var(--text-secondary);"><?= htmlspecialchars($payment['package_name']) ?></div>
            </div>
        </div>

        <!-- Financial Summary Breakdown -->
        <table class="table-breakdown">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="color:var(--text-secondary);">Total Package Amount</td>
                    <td style="text-align:right;font-weight:700;"><?= formatCurrency($totalAmount) ?></td>
                </tr>
                <tr style="background:rgba(39,174,96,0.08);">
                    <td style="color:#27ae60;font-weight:700;">Amount Paid Received</td>
                    <td style="text-align:right;font-weight:800;color:#27ae60;font-size:1.05rem;"><?= formatCurrency($payment['amount_paid']) ?></td>
                </tr>
                <tr>
                    <td style="color:var(--text-muted);">Remaining Booking Balance</td>
                    <td style="text-align:right;font-weight:700;color:<?= $remBalance > 0 ? 'var(--accent-red)' : '#27ae60' ?>;">
                        <?= $remBalance > 0 ? formatCurrency($remBalance) : 'PAID OFF' ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Payment Method & Cashier -->
        <div class="footer-bar">
            <div>
                Method: <strong style="color:#fff;"><?= ucwords(str_replace('_',' ', $payment['payment_method'] ?? 'cash')) ?></strong>
                <?php if (!empty($payment['reference_no'])): ?>
                    <span style="font-size:0.75rem;">(Ref: <?= htmlspecialchars($payment['reference_no']) ?>)</span>
                <?php endif; ?>
            </div>
            <div>
                Processed By: <strong style="color:#fff;"><?= htmlspecialchars($payment['cashier_name']) ?></strong>
            </div>
        </div>

        <div style="text-align:center;margin-top:20px;font-size:0.75rem;color:var(--text-muted);">
            <i class="fa-solid fa-circle-check" style="color:#27ae60;margin-right:4px;"></i> Verified Official Electronic Receipt
        </div>
    </div>

    <!-- Action Bar -->
    <div class="action-bar">
        <button onclick="window.history.back()" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <button onclick="window.print()" class="btn btn-primary"><i class="fa-solid fa-print"></i> Print Receipt</button>
    </div>
</div>

</body>
</html>
