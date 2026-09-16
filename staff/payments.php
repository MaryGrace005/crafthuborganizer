<?php
$pageTitle = 'Payments';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin', 'staff', 'cashier']);

$db     = getDB();
$filter = sanitize($_GET['filter'] ?? 'unpaid');
$allowed = ['all','unpaid','partial','paid','pending'];
if (!in_array($filter, $allowed)) $filter = 'unpaid';

$sql = "SELECT b.*, b.booking_id AS id, u.name AS customer_name, u.email AS customer_email, u.contact_no AS customer_phone,
               p.package_name AS package_name, v.venue_name AS venue_name,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS calc_paid,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id AND payment_type = 'downpayment') AS downpayment_paid,
               (SELECT MAX(payment_id) FROM payments WHERE booking_id = b.booking_id) AS latest_payment_id
        FROM bookings b
        JOIN users u ON b.customer_id = u.user_id
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id
        WHERE b.status != 'Cancelled'";

$params = [];
if ($filter === 'unpaid') {
    $sql .= " AND b.status = 'Confirmed' AND (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) = 0";
} elseif ($filter === 'partial') {
    $sql .= " AND (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) > 0 AND (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) < b.total_amount AND b.status != 'Pending'";
} elseif ($filter === 'paid') {
    $sql .= " AND (b.status = 'Paid' OR (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) >= b.total_amount)";
} elseif ($filter === 'pending') {
    $sql .= " AND b.status = 'Pending'";
}
$sql .= " ORDER BY b.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Payments</h1>
        <p>Manage, track, and process customer booking payments &amp; balances</p>
    </div>
</div>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach ($allowed as $f): ?>
        <a href="?filter=<?= $f ?>" class="btn btn-sm <?= $filter === $f ? 'btn-primary' : 'btn-secondary' ?>">
            <?= ucfirst($f) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <div class="search-bar" style="margin:0;flex:1;">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-search"></i>
                <input type="text" class="form-control" placeholder="Search customer payments..." data-search-table="paymentsTable">
            </div>
        </div>
    </div>

    <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-money-bill-wave"></i>
            <h3>No Bookings Found</h3>
            <p>No bookings match the selected filter.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" id="paymentsTable">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Package</th>
                        <th>Total Amount</th>
                        <th>Downpayment</th>
                        <th>Amount Paid</th>
                        <th>Remaining Balance</th>
                        <th>Status</th>
                        <th>Payment Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b):
                        $totAmt      = (float)($b['total_amount'] ?? 0);
                        $amtPaid     = (float)($b['calc_paid'] > 0 ? $b['calc_paid'] : ($b['amount_paid'] ?? 0));
                        $downpayment = (float)($b['downpayment_paid'] ?? 0);
                        $balance     = max(0.0, $totAmt - $amtPaid);
                        $payStatus   = ($amtPaid >= $totAmt && $totAmt > 0) ? 'paid' : ($amtPaid > 0 ? 'partial' : 'unpaid');
                    ?>
                    <tr>
                        <td><strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($b)) ?></strong></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($b['customer_name']) ?></div>
                            <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($b['customer_phone'] ?? '') ?></div>
                        </td>
                        <td><?= htmlspecialchars($b['package_name']) ?></td>
                        <td style="font-weight:600;"><?= formatCurrency($totAmt) ?></td>
                        <td>
                            <?php if ($downpayment > 0): ?>
                                <span class="badge badge-info"><?= formatCurrency($downpayment) ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:0.82rem;">None</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#27ae60;font-weight:700;"><?= formatCurrency($amtPaid) ?></td>
                        <td>
                            <?php if ($balance > 0): ?>
                                <strong style="color:var(--accent-red);"><?= formatCurrency($balance) ?></strong>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Paid Off</span>
                            <?php endif; ?>
                        </td>
                        <td><?= statusBadge($b['status']) ?></td>
                        <td><?= statusBadge($payStatus) ?></td>
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                                <a href="<?= APP_URL ?>/booking_images.php?booking_id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm" style="white-space:nowrap;" title="View Event Photos &amp; Attachments">
                                    <i class="fa-solid fa-camera"></i> Photos
                                </a>
                                <?php if ($b['status'] === 'Pending'): ?>
                                    <span class="badge badge-warning" style="white-space:nowrap;" title="Cannot collect payment: Booking is still pending confirmation">
                                        <i class="fa-solid fa-clock"></i> Pending Confirmation
                                    </span>
                                <?php elseif ($payStatus !== 'paid'): ?>
                                    <a href="<?= APP_URL ?>/staff/process_payment.php?id=<?= $b['id'] ?>"
                                       class="btn btn-success btn-sm" style="white-space:nowrap;">
                                        <i class="fa-solid fa-money-bill"></i> Collect Payment
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($b['latest_payment_id'])): ?>
                                    <a href="<?= APP_URL ?>/receipt.php?id=<?= $b['latest_payment_id'] ?>" target="_blank"
                                       class="btn btn-secondary btn-sm" style="white-space:nowrap;" title="View Official Receipt">
                                        <i class="fa-solid fa-receipt"></i> Receipt
                                    </a>
                                <?php endif; ?>
                                <?php if ($payStatus === 'paid' && empty($b['latest_payment_id'])): ?>
                                    <span class="badge badge-success" style="white-space:nowrap;"><i class="fa-solid fa-check"></i> Fully Paid</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
