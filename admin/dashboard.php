<?php
$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);

$stats = getDashboardStats('admin');
$db    = getDB();

// Recent bookings
$recent = $db->query("
    SELECT b.*, b.booking_id AS id, u.name AS customer_name, p.package_name AS package_name
    FROM bookings b 
    JOIN users u ON b.customer_id = u.user_id 
    JOIN packages p ON b.package_id = p.package_id
    ORDER BY b.created_at DESC LIMIT 6
")->fetchAll();

// Revenue by month (last 6 months)
$revenueStmt = $db->query("
    SELECT DATE_FORMAT(payment_date,'%b %Y') AS month,
           MONTH(payment_date) AS m, YEAR(payment_date) AS y,
           SUM(amount_paid) AS total
    FROM payments
    WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY y, m ORDER BY y, m
");
$revenueData = $revenueStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Admin Dashboard</h1>
        <p>Overview of the CraftHub system</p>
    </div>
    <div style="font-size:0.9rem;color:var(--text-secondary);"><?= date('l, F j, Y') ?></div>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card" style="--stat-color:#e94560;">
        <div class="stat-icon" style="background:rgba(233,69,96,0.2);color:#e94560;"><i class="fa-solid fa-users"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['total_users'] ?></div>
            <div class="stat-label">Customers</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#f5a623;">
        <div class="stat-icon" style="background:rgba(245,166,35,0.2);color:#f5a623;"><i class="fa-solid fa-box-open"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['total_packages'] ?></div>
            <div class="stat-label">Active Packages</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#4ecdc4;">
        <div class="stat-icon" style="background:rgba(78,205,196,0.2);color:#4ecdc4;"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['total_bookings'] ?></div>
            <div class="stat-label">Total Bookings</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#27ae60;">
        <div class="stat-icon" style="background:rgba(39,174,96,0.2);color:#27ae60;"><i class="fa-solid fa-peso-sign"></i></div>
        <div class="stat-info">
            <div class="stat-value" style="font-size:1.2rem;"><?= formatCurrency($stats['total_revenue']) ?></div>
            <div class="stat-label">Total Revenue</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#9b59b6;">
        <div class="stat-icon" style="background:rgba(155,89,182,0.2);color:#9b59b6;"><i class="fa-solid fa-clock"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['pending_bookings'] ?></div>
            <div class="stat-label">Pending Bookings</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#e67e22;">
        <div class="stat-icon" style="background:rgba(230,126,34,0.2);color:#e67e22;"><i class="fa-solid fa-location-dot"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['total_venues'] ?></div>
            <div class="stat-label">Available Venues</div>
        </div>
    </div>
</div>

<div class="grid-2" style="align-items:start;">
    <!-- Recent Bookings -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Bookings</h2>
            <a href="<?= APP_URL ?>/admin/bookings.php" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr><th>Ref</th><th>Customer</th><th>Package</th><th>Status</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $b): ?>
                    <tr>
                        <td><span style="color:var(--accent-teal);font-size:0.82rem;"><?= htmlspecialchars(getBookingRef($b)) ?></span></td>
                        <td><?= htmlspecialchars($b['customer_name']) ?></td>
                        <td><?= htmlspecialchars($b['package_name']) ?></td>
                        <td><?= statusBadge($b['status']) ?></td>
                        <td><?= formatCurrency($b['total_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Revenue Summary -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-chart-line"></i> Revenue (Last 6 Months)</h2>
            <a href="<?= APP_URL ?>/admin/reports.php" class="btn btn-secondary btn-sm">Full Reports</a>
        </div>
        <?php if (empty($revenueData)): ?>
            <div class="empty-state" style="padding:30px;"><i class="fa-solid fa-chart-bar"></i><p>No revenue data yet.</p></div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:12px;">
                <?php
                $maxVal = max(array_column($revenueData, 'total')) ?: 1;
                foreach ($revenueData as $r):
                    $pct = ($r['total'] / $maxVal) * 100;
                ?>
                <div>
                    <div class="flex-between" style="font-size:0.85rem;margin-bottom:6px;">
                        <span style="color:var(--text-secondary);"><?= $r['month'] ?></span>
                        <strong style="color:var(--accent-gold);"><?= formatCurrency($r['total']) ?></strong>
                    </div>
                    <div style="background:var(--bg-card);border-radius:4px;height:8px;overflow:hidden;">
                        <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,var(--accent-red),var(--accent-gold));border-radius:4px;transition:width 1s ease;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
