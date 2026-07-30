<?php
$pageTitle = 'My Bookings';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);

$user = getCurrentUser();
$db   = getDB();

$filter = sanitize($_GET['filter'] ?? 'all');
$where  = "WHERE b.customer_id = {$user['id']}";
if ($filter !== 'all') $where .= " AND b.status = '" . $db->quote($filter) . "'";

// Simple non-parameterized for filter (safe — values are whitelisted)
$allowedFilters = ['all','pending','confirmed','completed','cancelled'];
if (!in_array($filter, $allowedFilters)) $filter = 'all';

$sql = "SELECT b.*, p.name AS package_name, v.name AS venue_name
        FROM bookings b
        JOIN packages p ON b.package_id = p.id
        LEFT JOIN venues v ON b.venue_id = v.id
        WHERE b.customer_id = ?";
if ($filter !== 'all') $sql .= " AND b.status = ?";
$sql .= " ORDER BY b.created_at DESC";

$stmt = $db->prepare($sql);
$params = $filter !== 'all' ? [$user['id'], $filter] : [$user['id']];
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>My Bookings</h1>
        <p>Track all your craft event bookings</p>
    </div>
    <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> New Booking
    </a>
</div>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach (['all','pending','confirmed','completed','cancelled'] as $f): ?>
        <a href="?filter=<?= $f ?>" class="btn btn-sm <?= $filter === $f ? 'btn-primary' : 'btn-secondary' ?>">
            <?= ucfirst($f) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-calendar-xmark"></i>
            <h3>No Bookings Found</h3>
            <p><?= $filter !== 'all' ? "No {$filter} bookings." : "You haven't made any bookings yet." ?></p>
            <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-primary">Browse Packages</a>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" id="bookingsTable">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Package</th>
                        <th>Venue</th>
                        <th>Event Date</th>
                        <th>Guests</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td><strong style="color:var(--accent-teal);"><?= $b['booking_reference'] ?></strong></td>
                        <td><?= htmlspecialchars($b['package_name']) ?></td>
                        <td><?= htmlspecialchars($b['venue_name'] ?? '—') ?></td>
                        <td><?= formatDate($b['event_date']) ?><br>
                            <small style="color:var(--text-muted);"><?= date('g:i A', strtotime($b['event_time'])) ?></small>
                        </td>
                        <td><?= $b['num_guests'] ?></td>
                        <td><?= formatCurrency($b['total_amount']) ?></td>
                        <td><?= formatCurrency($b['amount_paid']) ?></td>
                        <td><?= statusBadge($b['status']) ?></td>
                        <td><?= statusBadge($b['payment_status']) ?></td>
                        <td>
                            <?php if ($b['status'] === 'pending'): ?>
                                <a href="<?= APP_URL ?>/customer/cancel_booking.php?id=<?= $b['id'] ?>"
                                   class="btn btn-danger btn-sm"
                                   data-confirm="Cancel booking <?= $b['booking_reference'] ?>?">
                                    <i class="fa-solid fa-xmark"></i> Cancel
                                </a>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:0.82rem;">
                                    <?= ucfirst($b['status']) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
