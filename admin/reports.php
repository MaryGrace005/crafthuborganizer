<?php
$pageTitle = 'System Reports';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

$dateFrom = sanitize($_GET['from'] ?? date('Y-m-01'));
$dateTo   = sanitize($_GET['to']   ?? date('Y-m-d'));

// Summary Revenue
$revStmt = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?");
$revStmt->execute([$dateFrom, $dateTo]);
$totalRevenue = $revStmt->fetchColumn();

// Total Bookings in range
$bookStmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) BETWEEN ? AND ?");
$bookStmt->execute([$dateFrom, $dateTo]);
$totalBookings = $bookStmt->fetchColumn();

// Most popular packages
$topPkgStmt = $db->prepare("
    SELECT p.package_name AS name, COUNT(b.booking_id) AS booking_count, SUM(b.total_amount) AS revenue
    FROM bookings b
    JOIN packages p ON b.package_id = p.package_id
    WHERE DATE(b.created_at) BETWEEN ? AND ?
    GROUP BY p.package_id ORDER BY booking_count DESC LIMIT 5
");
$topPkgStmt->execute([$dateFrom, $dateTo]);
$topPackages = $topPkgStmt->fetchAll();

// Payments detail
$paymentsStmt = $db->prepare("
    SELECT py.*, py.payment_id AS id, u.name AS cashier_name, c.name AS customer_name
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN users u ON py.cashier_id = u.user_id
    JOIN users c ON b.customer_id = c.user_id
    WHERE DATE(py.payment_date) BETWEEN ? AND ?
    ORDER BY py.payment_date DESC
");
$paymentsStmt->execute([$dateFrom, $dateTo]);
$payments = $paymentsStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Reports & Analytics</h1>
        <p>Comprehensive system metrics and revenue reports</p>
    </div>
    <button class="btn btn-secondary" data-print>
        <i class="fa-solid fa-print"></i> Print Report
    </button>
</div>

<!-- Date Filter -->
<div class="card mb-3">
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
            <i class="fa-solid fa-filter"></i> Apply Filter
        </button>
        <a href="?" class="btn btn-secondary">Reset</a>
    </form>
</div>

<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card" style="--stat-color:#27ae60;">
        <div class="stat-icon" style="background:rgba(39,174,96,0.2);color:#27ae60;"><i class="fa-solid fa-peso-sign"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= formatCurrency($totalRevenue) ?></div>
            <div class="stat-label">Total Revenue</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#4ecdc4;">
        <div class="stat-icon" style="background:rgba(78,205,196,0.2);color:#4ecdc4;"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalBookings ?></div>
            <div class="stat-label">Total Bookings</div>
        </div>
    </div>
</div>

<div class="grid-2" style="align-items:start;margin-bottom:24px;">
    <!-- Top Packages -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-trophy" style="color:var(--accent-gold);"></i> Most Popular Packages</h2>
        </div>
        <?php if (empty($topPackages)): ?>
            <div class="empty-state" style="padding:20px;"><p>No booking data in date range.</p></div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr><th>Package Name</th><th>Bookings</th><th>Revenue</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topPackages as $tp): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($tp['name']) ?></strong></td>
                            <td><span class="badge badge-info"><?= $tp['booking_count'] ?></span></td>
                            <td style="color:var(--accent-gold);font-weight:700;"><?= formatCurrency($tp['revenue']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Payment Logs in Range -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-receipt"></i> Payment Records</h2>
        </div>
        <?php if (empty($payments)): ?>
            <div class="empty-state" style="padding:20px;"><p>No payment records in date range.</p></div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr><th>Ref</th><th>Customer</th><th>Amount</th><th>Method</th><th>Cashier</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $py): ?>
                        <tr>
                            <td><span style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($py)) ?></span></td>
                            <td><?= htmlspecialchars($py['customer_name']) ?></td>
                            <td style="color:#27ae60;font-weight:700;"><?= formatCurrency($py['amount_paid']) ?></td>
                            <td><?= ucwords(str_replace('_',' ', $py['payment_method'] ?? 'cash')) ?></td>
                            <td><?= htmlspecialchars($py['cashier_name']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
