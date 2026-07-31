<?php
$pageTitle = 'My Collections';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);

$staffMember = getCurrentUser();
$db          = getDB();

$dateFrom = sanitize($_GET['from'] ?? date('Y-m-01'));
$dateTo   = sanitize($_GET['to']   ?? date('Y-m-d'));

$userId = $staffMember['user_id'] ?? $staffMember['id'];

$stmt = $db->prepare("
    SELECT py.*, py.payment_id AS id, b.total_amount AS booking_total,
           u.name AS customer_name, p.package_name AS package_name
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN users u ON b.customer_id = u.user_id
    JOIN packages p ON b.package_id = p.package_id
    WHERE py.cashier_id = ?
      AND DATE(py.payment_date) BETWEEN ? AND ?
    ORDER BY py.payment_date DESC
");
$stmt->execute([$userId, $dateFrom, $dateTo]);
$payments = $stmt->fetchAll();

// Totals
$totalStmt = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE cashier_id = ? AND DATE(payment_date) BETWEEN ? AND ?");
$totalStmt->execute([$userId, $dateFrom, $dateTo]);
$totalCollected = $totalStmt->fetchColumn();

// Type breakdown
$methodStmt = $db->prepare("SELECT payment_type AS payment_method, SUM(amount_paid) AS total, COUNT(*) AS count FROM payments WHERE cashier_id = ? AND DATE(payment_date) BETWEEN ? AND ? GROUP BY payment_type");
$methodStmt->execute([$userId, $dateFrom, $dateTo]);
$methodBreakdown = $methodStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>My Collections</h1>
        <p>Summary of payments you've processed</p>
    </div>
</div>

<!-- Date Filter -->
<div class="card" style="margin-bottom:20px;">
    <form method="GET" action="" style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;flex:1;min-width:150px;">
            <label class="form-label">From Date</label>
            <input type="date" name="from" class="form-control" value="<?= $dateFrom ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:150px;">
            <label class="form-label">To Date</label>
            <input type="date" name="to" class="form-control" value="<?= $dateTo ?>">
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-filter"></i> Filter
        </button>
        <a href="?" class="btn btn-secondary">Reset</a>
    </form>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card" style="--stat-color:#27ae60;">
        <div class="stat-icon" style="background:rgba(39,174,96,0.2);color:#27ae60;"><i class="fa-solid fa-peso-sign"></i></div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:1.4rem;"><?= formatCurrency($totalCollected) ?></div>
            <div class="stat-label">Total Collected</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#4ecdc4;">
        <div class="stat-icon" style="background:rgba(78,205,196,0.2);color:#4ecdc4;"><i class="fa-solid fa-receipt"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= count($payments) ?></div>
            <div class="stat-label">Transactions</div>
        </div>
    </div>
    <?php foreach ($methodBreakdown as $mb): ?>
    <div class="stat-card" style="--stat-color:#f5a623;">
        <div class="stat-icon" style="background:rgba(245,166,35,0.2);color:#f5a623;"><i class="fa-solid fa-coins"></i></div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:1.2rem;"><?= formatCurrency($mb['total']) ?></div>
            <div class="stat-label"><?= ucwords(str_replace('_',' ', $mb['payment_method'] ?? 'cash')) ?> (<?= $mb['count'] ?>)</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Transactions Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-list"></i> Transaction Log</h2>
        <button class="btn btn-secondary btn-sm" data-print>
            <i class="fa-solid fa-print"></i> Print
        </button>
    </div>

    <?php if (empty($payments)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-coins"></i>
            <h3>No Collections</h3>
            <p>No payments found for the selected date range.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date &amp; Time</th>
                        <th>Customer</th>
                        <th>Booking Ref</th>
                        <th>Package</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference No.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $i => $py): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= formatDateTime($py['payment_date']) ?></td>
                        <td><?= htmlspecialchars($py['customer_name']) ?></td>
                        <td><strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($py)) ?></strong></td>
                        <td><?= htmlspecialchars($py['package_name']) ?></td>
                        <td><strong style="color:#27ae60;"><?= formatCurrency($py['amount_paid']) ?></strong></td>
                        <td><span class="badge badge-info"><?= ucwords(str_replace('_',' ', $py['payment_method'] ?? 'cash')) ?></span></td>
                        <td><?= htmlspecialchars($py['reference_no'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right;font-weight:700;color:var(--text-secondary);padding:14px 16px;">TOTAL</td>
                        <td colspan="3" style="font-weight:800;color:var(--accent-gold);font-size:1.1rem;padding:14px 16px;"><?= formatCurrency($totalCollected) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
