<?php
$pageTitle = 'My Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);
requireApproved();

$user  = getCurrentUser();
$stats = getDashboardStats('customer', $user['id']);
$db    = getDB();

// Recent bookings
$stmt = $db->prepare("
    SELECT b.*, b.booking_id AS id, p.package_name AS package_name, v.venue_name AS venue_name,
           (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS calc_paid
    FROM bookings b
    JOIN packages p ON b.package_id = p.package_id
    LEFT JOIN venues v ON b.venue_id = v.venue_id
    WHERE b.customer_id = ?
    ORDER BY b.created_at DESC LIMIT 5
");
$stmt->execute([$user['user_id'] ?? $user['id']]);
$recentBookings = $stmt->fetchAll();

// Recent payment receipts for customer
$payStmt = $db->prepare("
    SELECT py.*, py.payment_id AS id, b.total_amount, p.package_name AS package_name,
           u.name AS cashier_name
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN packages p ON b.package_id = p.package_id
    JOIN users u ON py.cashier_id = u.user_id
    WHERE b.customer_id = ?
    ORDER BY py.payment_date DESC LIMIT 4
");
$payStmt->execute([$user['user_id'] ?? $user['id']]);
$recentReceipts = $payStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Welcome back, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>! 👋</h1>
        <p>Here's an overview of your craft bookings, payments, and activities.</p>
        <?php if (!empty($user['id_code'])): ?>
        <div style="display:inline-flex;align-items:center;gap:8px;margin-top:8px;padding:6px 14px;background:rgba(78,205,196,0.1);border:1px solid rgba(78,205,196,0.25);border-radius:20px;">
            <i class="fa-solid fa-id-badge" style="color:#4ecdc4;"></i>
            <span style="font-size:0.82rem;color:rgba(255,255,255,0.5);">Account ID:</span>
            <code style="font-weight:800;color:#4ecdc4;font-size:0.9rem;letter-spacing:0.04em;"><?= htmlspecialchars($user['id_code']) ?></code>
        </div>
        <?php endif; ?>
    </div>
    <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> Book a Package
    </a>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card" style="--stat-color: #4ecdc4;">
        <div class="stat-icon" style="background:rgba(78,205,196,0.2);color:#4ecdc4;">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['my_bookings'] ?></div>
            <div class="stat-label">Total Bookings</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color: #f5a623;">
        <div class="stat-icon" style="background:rgba(245,166,35,0.2);color:#f5a623;">
            <i class="fa-solid fa-clock"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['pending'] ?></div>
            <div class="stat-label">Pending</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color: #27ae60;">
        <div class="stat-icon" style="background:rgba(39,174,96,0.2);color:#27ae60;">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['confirmed'] ?></div>
            <div class="stat-label">Confirmed</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color: #e94560;">
        <div class="stat-icon" style="background:rgba(233,69,96,0.2);color:#e94560;">
            <i class="fa-solid fa-peso-sign"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= formatCurrency($stats['total_paid']) ?></div>
            <div class="stat-label">Total Paid</div>
        </div>
    </div>
</div>

<!-- Recent Bookings -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Bookings</h2>
        <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-secondary btn-sm">View All</a>
    </div>

    <?php if (empty($recentBookings)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-calendar-xmark"></i>
            <h3>No Bookings Yet</h3>
            <p>Start your craft journey by booking a package!</p>
            <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-primary">
                <i class="fa-solid fa-box-open"></i> Browse Packages
            </a>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Package</th>
                        <th>Event Date</th>
                        <th>Total Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Payment Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $b): 
                        $total     = (float)($b['total_amount'] ?? 0);
                        $paid      = (float)($b['calc_paid'] > 0 ? $b['calc_paid'] : ($b['amount_paid'] ?? 0));
                        $balance   = max(0.0, $total - $paid);
                        $payStatus = ($paid >= $total && $total > 0) ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                    ?>
                    <tr>
                        <td><strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($b)) ?></strong></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($b['package_name']) ?></div>
                            <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($b['venue_name'] ?? 'No Venue') ?></div>
                        </td>
                        <td><?= formatDate($b['event_date']) ?></td>
                        <td style="font-weight:600;"><?= formatCurrency($total) ?></td>
                        <td style="color:#27ae60;font-weight:700;"><?= formatCurrency($paid) ?></td>
                        <td>
                            <?php if ($balance > 0): ?>
                                <strong style="color:var(--accent-red);"><?= formatCurrency($balance) ?></strong>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Paid</span>
                            <?php endif; ?>
                        </td>
                        <td><?= statusBadge($payStatus) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Recent Payment Receipts Card -->
<div class="card" style="margin-top:24px;">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-receipt" style="color:var(--accent-gold);"></i> Recent Payment Receipts</h2>
        <a href="<?= APP_URL ?>/customer/payment_history.php" class="btn btn-secondary btn-sm">View All Receipts</a>
    </div>

    <?php if (empty($recentReceipts)): ?>
        <div class="empty-state" style="padding:24px;">
            <p style="color:var(--text-muted);font-size:0.88rem;">No payment receipts found yet.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Receipt OR#</th>
                        <th>Booking Ref</th>
                        <th>Package</th>
                        <th>Amount Paid</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentReceipts as $rcpt): ?>
                    <tr>
                        <td><strong style="color:var(--accent-gold);"><?= htmlspecialchars($rcpt['or_number'] ?? ('OR-' . $rcpt['id'])) ?></strong></td>
                        <td><strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($rcpt)) ?></strong></td>
                        <td><?= htmlspecialchars($rcpt['package_name']) ?></td>
                        <td><strong style="color:#27ae60;"><?= formatCurrency($rcpt['amount_paid']) ?></strong></td>
                        <td><span class="badge badge-secondary"><?= ucwords(str_replace('_',' ', $rcpt['payment_method'] ?? 'cash')) ?></span></td>
                        <td><?= formatDateTime($rcpt['payment_date']) ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/receipt.php?id=<?= (int)$rcpt['id'] ?>" target="_blank" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-file-invoice"></i> View Receipt
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
