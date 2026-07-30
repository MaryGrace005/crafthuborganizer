<?php
$pageTitle = 'Manage Bookings';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'update_status') {
        $status = sanitize($_POST['status'] ?? 'pending');
        $allowed = ['pending', 'confirmed', 'cancelled', 'completed'];
        if (in_array($status, $allowed)) {
            $stmt = $db->prepare("UPDATE bookings SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            logAudit($_SESSION['user_id'], 'UPDATE_BOOKING_STATUS', "Updated booking #{$id} status to {$status}", 'bookings');
            setFlash('success', "Booking status updated to " . ucfirst($status) . ".");
        }
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM bookings WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($_SESSION['user_id'], 'DELETE_BOOKING', "Deleted booking #{$id}", 'bookings');
        setFlash('success', "Booking deleted.");
    }
    redirect(APP_URL . '/admin/bookings.php');
}

$statusFilter = sanitize($_GET['status'] ?? 'all');
$sql = "SELECT b.*, b.booking_id AS id, u.name AS customer_name, u.email AS customer_email, p.package_name AS package_name, v.venue_name AS venue_name
        FROM bookings b
        JOIN users u ON b.customer_id = u.user_id
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id";
$params = [];

if ($statusFilter !== 'all') {
    $sql .= " WHERE b.status = ?";
    $params[] = ucfirst($statusFilter);
}

$sql .= " ORDER BY b.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>All Bookings</h1>
        <p>Review and manage customer event bookings</p>
    </div>
</div>

<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach (['all', 'pending', 'confirmed', 'completed', 'cancelled'] as $st): ?>
        <a href="?status=<?= $st ?>" class="btn btn-sm <?= $statusFilter === $st ? 'btn-primary' : 'btn-secondary' ?>">
            <?= ucfirst($st) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="search-bar">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" class="form-control" placeholder="Search bookings..." data-search-table="allBookingsTable">
        </div>
    </div>

    <div class="table-wrapper">
        <table class="table" id="allBookingsTable">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Package</th>
                    <th>Venue</th>
                    <th>Date & Time</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                <tr>
                    <td><strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($b)) ?></strong></td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($b['customer_email']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($b['package_name']) ?></td>
                    <td><?= htmlspecialchars($b['venue_name'] ?? '—') ?></td>
                    <td><?= formatDate($b['event_date']) ?><br><small style="color:var(--text-muted);"><?= date('g:i A', strtotime($b['event_time'] ?? '09:00:00')) ?></small></td>
                    <td><?= formatCurrency($b['total_amount']) ?></td>
                    <td><?= statusBadge($b['status']) ?></td>
                    <td><?= statusBadge($b['status']) ?></td>
                    <td>
                        <a href="<?= APP_URL ?>/booking_images.php?booking_id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm" title="View & Upload Photos">
                            <i class="fa-solid fa-camera"></i> Photos
                        </a>
                        <button class="btn btn-warning btn-sm" data-modal="editStatusModal"
                                data-edit='<?= json_encode(['id'=>$b['id'], 'status'=>$b['status']]) ?>'>
                            <i class="fa-solid fa-pen-to-square"></i> Status
                        </button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete booking <?= htmlspecialchars(getBookingRef($b)) ?>?">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Status Modal -->
<div class="modal-overlay" id="editStatusModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title">Update Booking Status</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Booking Status</label>
                    <select name="status" class="form-control">
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Update Status</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
