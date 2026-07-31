<?php
$pageTitle = 'Payments';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);

$db     = getDB();
$filter = sanitize($_GET['filter'] ?? 'unpaid');
$allowed = ['all','unpaid','partial','paid'];
if (!in_array($filter, $allowed)) $filter = 'unpaid';

$sql = "SELECT b.*, b.booking_id AS id, u.name AS customer_name, u.email AS customer_email, u.contact_no AS customer_phone,
               p.package_name AS package_name, v.venue_name AS venue_name,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS calc_paid,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id AND payment_type = 'downpayment') AS downpayment_paid
        FROM bookings b
        JOIN users u ON b.customer_id = u.user_id
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id
        WHERE b.status != 'Cancelled'";

$params = [];
if ($filter === 'unpaid') {
    $sql .= " AND b.status = 'Pending'";
} elseif ($filter === 'paid') {
    $sql .= " AND b.status = 'Paid'";
} elseif ($filter === 'partial') {
    $sql .= " AND b.status = 'Confirmed'";
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
                            <div style="display:flex;gap:6px;align-items:center;">
                                <a href="<?= APP_URL ?>/booking_images.php?booking_id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm" title="View Event Photos &amp; Attachments">
                                    <i class="fa-solid fa-camera"></i> Photos
                                </a>
                                <?php if ($payStatus !== 'paid'): ?>
                                    <a href="<?= APP_URL ?>/staff/process_payment.php?id=<?= $b['id'] ?>"
                                       class="btn btn-success btn-sm">
                                        <i class="fa-solid fa-money-bill"></i> Collect Payment
                                    </a>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-check"></i> Fully Paid</span>
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
