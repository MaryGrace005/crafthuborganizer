<?php
$pageTitle = 'My Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);

$user  = getCurrentUser();
$stats = getDashboardStats('customer', $user['id']);
$db    = getDB();

// Recent bookings
$stmt = $db->prepare("
    SELECT b.*, p.name AS package_name, v.name AS venue_name
    FROM bookings b
    JOIN packages p ON b.package_id = p.id
    LEFT JOIN venues v ON b.venue_id = v.id
    WHERE b.customer_id = ?
    ORDER BY b.created_at DESC LIMIT 5
");
$stmt->execute([$user['id']]);
$recentBookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Welcome back, <?= htmlspecialchars(explode(' ', $user['name'])[0]) ?>! 👋</h1>
        <p>Here's an overview of your craft bookings and activities.</p>
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
                        <th>Venue</th>
                        <th>Event Date</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $b): ?>
                    <tr>
                        <td><strong style="color:var(--accent-teal);"><?= $b['booking_reference'] ?></strong></td>
                        <td><?= htmlspecialchars($b['package_name']) ?></td>
                        <td><?= htmlspecialchars($b['venue_name'] ?? '—') ?></td>
                        <td><?= formatDate($b['event_date']) ?></td>
                        <td><?= formatCurrency($b['total_amount']) ?></td>
                        <td><?= statusBadge($b['status']) ?></td>
                        <td><?= statusBadge($b['payment_status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
