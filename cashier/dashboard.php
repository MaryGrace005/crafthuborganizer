<?php
$pageTitle = 'Cashier Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole(['cashier']);

$user  = getCurrentUser();
$stats = getDashboardStats('cashier', $user['id']);
$db    = getDB();

// Bookings needing payment
$stmt = $db->query("
    SELECT b.*, u.name AS customer_name, p.name AS package_name
    FROM bookings b
    JOIN users u ON b.customer_id = u.id
    JOIN packages p ON b.package_id = p.id
    WHERE b.payment_status != 'paid' AND b.status != 'cancelled'
    ORDER BY b.created_at DESC LIMIT 8
");
$pendingPayments = $stmt->fetchAll();

// Today's payments
$todayStmt = $db->prepare("
    SELECT py.*, b.booking_reference, u.name AS customer_name
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN users u ON b.customer_id = u.id
    WHERE py.cashier_id = ? AND DATE(py.payment_date) = CURDATE()
    ORDER BY py.payment_date DESC
");
$todayStmt->execute([$user['id']]);
$todayPayments = $todayStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Cashier Dashboard</h1>
        <p><?= date('l, F j, Y') ?></p>
    </div>
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
            <a href="<?= APP_URL ?>/cashier/payments.php" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <?php if (empty($pendingPayments)): ?>
            <div class="empty-state" style="padding:30px;">
                <i class="fa-solid fa-circle-check" style="color:var(--accent-teal);"></i>
                <p>All payments are up to date!</p>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($pendingPayments as $b): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:var(--bg-card);border-radius:var(--radius-sm);border:1px solid var(--border-color);">
                    <div>
                        <div style="font-size:0.88rem;font-weight:600;"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= $b['booking_reference'] ?> • <?= htmlspecialchars($b['package_name']) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="color:var(--accent-gold);font-weight:700;font-size:0.9rem;"><?= formatCurrency($b['total_amount'] - $b['amount_paid']) ?></div>
                        <?= statusBadge($b['payment_status']) ?>
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
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= $py['booking_reference'] ?> • <?= ucwords(str_replace('_',' ',$py['payment_method'])) ?></div>
                    </div>
                    <div style="color:#27ae60;font-weight:700;"><?= formatCurrency($py['amount_paid']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
