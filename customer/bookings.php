<?php
$pageTitle = 'My Bookings';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);
requireApproved();

$user = getCurrentUser();
$db   = getDB();

$filter = sanitize($_GET['filter'] ?? 'all');
$where  = "WHERE b.customer_id = {$user['id']}";
if ($filter !== 'all') $where .= " AND b.status = '" . $db->quote($filter) . "'";

// Simple non-parameterized for filter (safe — values are whitelisted)
$allowedFilters = ['all','pending','confirmed','completed','cancelled'];
if (!in_array($filter, $allowedFilters)) $filter = 'all';

$sql = "SELECT b.*, b.booking_id AS id, p.package_name AS package_name, v.venue_name AS venue_name,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS calc_paid,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id AND payment_type = 'downpayment') AS downpayment_paid
        FROM bookings b
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id
        WHERE b.customer_id = ?";
if ($filter !== 'all') $sql .= " AND b.status = ?";
$sql .= " ORDER BY b.created_at DESC";

$stmt = $db->prepare($sql);
$userId = $user['user_id'] ?? $user['id'];
$params = $filter !== 'all' ? [$userId, $filter] : [$userId];
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>My Bookings</h1>
        <p>Track all your craft event bookings, downpayments, and balances</p>
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
                        <th>Package / Venue</th>
                        <th>Event Date</th>
                        <th>Total Amount</th>
                        <th>Downpayment</th>
                        <th>Total Paid</th>
                        <th>Remaining Balance</th>
                        <th>Payment Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): 
                        $total       = (float)($b['total_amount'] ?? 0);
                        $paid        = (float)($b['calc_paid'] > 0 ? $b['calc_paid'] : ($b['amount_paid'] ?? 0));
                        $downpayment = (float)($b['downpayment_paid'] ?? 0);
                        $balance     = max(0.0, $total - $paid);
                        $payStatus   = ($paid >= $total && $total > 0) ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                    ?>
                    <tr style="white-space:nowrap;">
                        <td style="white-space:nowrap;">
                            <span class="ref-chip"><?= htmlspecialchars(getBookingRef($b)) ?></span>
                        </td>
                        <td style="white-space:nowrap;">
                            <div style="font-weight:600;"><?= htmlspecialchars($b['package_name']) ?></div>
                            <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($b['venue_name'] ?? 'No Venue') ?></div>
                        </td>
                        <td style="white-space:nowrap;">
                            <div style="font-weight:500;"><?= formatDate($b['event_date']) ?></div>
                            <div style="font-size:0.78rem;color:var(--text-muted);"><?= date('g:i A', strtotime($b['event_time'] ?? '09:00:00')) ?></div>
                        </td>
                        <td style="font-weight:600;white-space:nowrap;"><?= formatCurrency($total) ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($downpayment > 0): ?>
                                <span class="badge badge-info"><?= formatCurrency($downpayment) ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:0.82rem;">None</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#27ae60;font-weight:700;white-space:nowrap;"><?= formatCurrency($paid) ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($balance > 0): ?>
                                <strong style="color:var(--accent-red);"><?= formatCurrency($balance) ?></strong>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Fully Paid</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;"><?= statusBadge($payStatus) ?></td>
                        <td style="white-space:nowrap;">
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                                <a href="<?= APP_URL ?>/booking_images.php?booking_id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm" style="white-space:nowrap;" title="View &amp; Upload Event Photos">
                                    <i class="fa-solid fa-camera"></i> Photos
                                </a>
                                <?php if (strtolower($b['status']) === 'pending'): ?>
                                    <a href="<?= APP_URL ?>/customer/cancel_booking.php?id=<?= $b['id'] ?>"
                                       class="btn btn-danger btn-sm"
                                       style="white-space:nowrap;"
                                       data-confirm="Cancel booking <?= htmlspecialchars(getBookingRef($b)) ?>?">
                                        <i class="fa-solid fa-xmark"></i> Cancel
                                    </a>
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
