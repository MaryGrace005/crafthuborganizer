<?php
$pageTitle = 'Staff Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);

$user  = getCurrentUser();
$stats = getDashboardStats('staff', $user['id']);
$db    = getDB();

// Bookings needing payment
$stmt = $db->query("
    SELECT b.*, b.booking_id AS id, u.name AS customer_name, p.package_name AS package_name,
           (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS calc_paid
    FROM bookings b
    JOIN users u ON b.customer_id = u.user_id
    JOIN packages p ON b.package_id = p.package_id
    WHERE b.status NOT IN ('Paid','Cancelled')
    ORDER BY b.created_at DESC LIMIT 8
");
$pendingPayments = $stmt->fetchAll();

// Today's payments
$todayStmt = $db->prepare("
    SELECT py.*, py.payment_id AS id, u.name AS customer_name
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN users u ON b.customer_id = u.user_id
    WHERE py.cashier_id = ? AND DATE(py.payment_date) = CURDATE()
    ORDER BY py.payment_date DESC
");
$userId = $user['user_id'] ?? $user['id'];
$todayStmt->execute([$userId]);
$todayPayments = $todayStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Staff Dashboard</h1>
        <p><?= date('l, F j, Y') ?></p>
    </div>
    <a href="<?= APP_URL ?>/staff/customers.php" class="btn btn-primary">
        <i class="fa-solid fa-user-plus"></i> Register Customer Account
    </a>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card" style="--stat-color:#f5a623;">
        <div class="stat-icon" style="background:rgba(245,166,35,0.2);color:#f5a623;">
            <i class="fa-solid fa-clock"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['pending_payments'] ?></div>
            <div class="stat-label">Pending Payments</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#27ae60;">
        <div class="stat-icon" style="background:rgba(39,174,96,0.2);color:#27ae60;">
            <i class="fa-solid fa-peso-sign"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= formatCurrency($stats['total_collected']) ?></div>
            <div class="stat-label">My Total Collections</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#4ecdc4;">
        <div class="stat-icon" style="background:rgba(78,205,196,0.2);color:#4ecdc4;">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['today_payments'] ?></div>
            <div class="stat-label">Today's Transactions</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#e94560;">
        <div class="stat-icon" style="background:rgba(233,69,96,0.2);color:#e94560;">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['confirmed_bookings'] ?></div>
            <div class="stat-label">Confirmed Bookings</div>
        </div>
    </div>
</div>

<div class="grid-2" style="align-items:start;">

    <!-- Pending Payments -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-circle-exclamation" style="color:var(--accent-gold);"></i> Needs Payment</h2>
            <a href="<?= APP_URL ?>/staff/payments.php" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <?php if (empty($pendingPayments)): ?>
            <div class="empty-state" style="padding:30px;">
                <i class="fa-solid fa-circle-check" style="color:var(--accent-teal);"></i>
                <p>All payments are up to date!</p>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($pendingPayments as $b): 
                    $totAmt  = (float)($b['total_amount'] ?? 0);
                    $amtPaid = (float)($b['calc_paid'] > 0 ? $b['calc_paid'] : ($b['amount_paid'] ?? 0));
                    $balance = max(0.0, $totAmt - $amtPaid);
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:var(--bg-card);border-radius:var(--radius-sm);border:1px solid var(--border-color);">
                    <div>
                        <div style="font-size:0.88rem;font-weight:600;"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars(getBookingRef($b)) ?> • <?= htmlspecialchars($b['package_name']) ?></div>
                        <div style="font-size:0.75rem;margin-top:2px;">
                            <span style="color:#27ae60;font-weight:600;"><?= formatCurrency($amtPaid) ?> Paid</span>
                            <span style="color:var(--text-muted);">/ <?= formatCurrency($totAmt) ?></span>
                        </div>
                    </div>
                    <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                        <div style="color:var(--accent-red);font-weight:700;font-size:0.9rem;"><?= formatCurrency($balance) ?> Due</div>
                        <a href="<?= APP_URL ?>/staff/process_payment.php?id=<?= $b['id'] ?>" class="btn btn-success btn-sm">
                            <i class="fa-solid fa-money-bill"></i> Pay
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Today's Transactions -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-receipt"></i> Today's Collections</h2>
        </div>
        <?php if (empty($todayPayments)): ?>
            <div class="empty-state" style="padding:30px;">
                <i class="fa-solid fa-coins"></i>
                <p>No collections recorded today yet.</p>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($todayPayments as $py): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:rgba(39,174,96,0.05);border-radius:var(--radius-sm);border:1px solid rgba(39,174,96,0.15);">
                    <div>
                        <div style="font-size:0.88rem;font-weight:600;"><?= htmlspecialchars($py['customer_name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars(getBookingRef($py)) ?> • <?= ucwords(str_replace('_',' ', $py['payment_method'] ?? 'cash')) ?></div>
                    </div>
                    <div style="color:#27ae60;font-weight:700;"><?= formatCurrency($py['amount_paid']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
