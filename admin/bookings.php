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
    <?php foreach (['all', 'pending', 'confirmed', 'completed', 'cancelled'] as $st):
        $icons = ['all'=>'fa-list','pending'=>'fa-clock','confirmed'=>'fa-circle-check','completed'=>'fa-flag-checkered','cancelled'=>'fa-ban'];
        $active = $statusFilter === $st;
    ?>
        <a href="?status=<?= $st ?>" class="filter-tab <?= $active ? 'filter-tab--active' : '' ?>">
            <i class="fa-solid <?= $icons[$st] ?>"></i>
            <?= ucfirst($st) ?>
        </a>
    <?php endforeach; ?>
</div>

<style>
/* ── Filter tabs ── */
.filter-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 30px;
    font-family: 'Outfit', sans-serif;
    font-size: 0.85rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-decoration: none;
    color: var(--text-secondary);
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.09);
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    white-space: nowrap;
    position: relative;
    overflow: hidden;
}
.filter-tab:hover {
    color: var(--text-primary);
    background: rgba(255,255,255,0.09);
    border-color: rgba(255,255,255,0.18);
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,0,0,0.3);
}
.filter-tab--active {
    background: linear-gradient(135deg, #e94560 0%, #c0392b 100%);
    color: #fff;
    border-color: rgba(233,69,96,0.4);
    box-shadow: 0 4px 18px rgba(233,69,96,0.45), inset 0 1px 0 rgba(255,255,255,0.15);
}
.filter-tab--active:hover {
    background: linear-gradient(135deg, #f0556e 0%, #d44030 100%);
    box-shadow: 0 8px 26px rgba(233,69,96,0.6), inset 0 1px 0 rgba(255,255,255,0.15);
    color: #fff;
}

/* ── Booking reference chip ── */
.ref-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    background: rgba(78,205,196,0.1);
    border: 1px solid rgba(78,205,196,0.25);
    border-radius: 8px;
    font-family: 'Outfit', sans-serif;
    font-size: 0.82rem;
    font-weight: 800;
    color: var(--accent-teal);
    letter-spacing: 0.02em;
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.25s ease;
}
.ref-chip:hover {
    background: rgba(78,205,196,0.18);
    border-color: rgba(78,205,196,0.5);
    box-shadow: 0 3px 12px rgba(78,205,196,0.2);
}

/* ── Amount chip ── */
.amount-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px 5px 5px;
    background: linear-gradient(135deg, rgba(245,166,35,0.12) 0%, rgba(230,126,34,0.07) 100%);
    border: 1px solid rgba(245,166,35,0.28);
    border-radius: 30px;
    font-family: 'Outfit', sans-serif;
    white-space: nowrap;
    box-shadow: 0 0 10px rgba(245,166,35,0.07), inset 0 1px 0 rgba(255,255,255,0.05);
    transition: all 0.28s ease;
}
.amount-chip:hover {
    border-color: rgba(245,166,35,0.5);
    box-shadow: 0 4px 16px rgba(245,166,35,0.24);
    transform: translateY(-1px);
}
.amount-chip .cur {
    width: 20px; height: 20px;
    background: rgba(245,166,35,0.2);
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.72rem; font-weight: 800;
    color: var(--accent-gold);
    flex-shrink: 0;
}
.amount-chip .amt {
    font-size: 0.9rem;
    font-weight: 800;
    color: #fff;
    line-height: 1;
}

/* ── Action group ── */
.bk-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
}

.btn-photos {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    font-size: 0.8rem;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    border-radius: 10px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.12);
    color: var(--text-primary);
    text-decoration: none;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.06);
    white-space: nowrap;
}
.btn-photos:hover {
    background: rgba(78,205,196,0.15);
    border-color: rgba(78,205,196,0.4);
    color: var(--accent-teal);
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(78,205,196,0.2);
}
.btn-photos i { font-size: 0.82rem; }

.btn-status-upd {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    font-size: 0.8rem;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    border-radius: 10px;
    background: linear-gradient(135deg, #f5a623 0%, #e67e22 100%);
    border: 1px solid rgba(245,166,35,0.3);
    color: #fff;
    cursor: pointer;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    box-shadow: 0 3px 12px rgba(245,166,35,0.35), inset 0 1px 0 rgba(255,255,255,0.2);
    white-space: nowrap;
    position: relative; overflow: hidden;
}
.btn-status-upd:hover {
    background: linear-gradient(135deg, #ffc04a 0%, #f08c2e 100%);
    transform: translateY(-2px);
    box-shadow: 0 7px 20px rgba(245,166,35,0.5), inset 0 1px 0 rgba(255,255,255,0.2);
}
.btn-status-upd:active { transform: scale(0.97); }

.btn-del-bk {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px; height: 32px;
    border-radius: 9px;
    background: linear-gradient(135deg, rgba(192,57,43,0.18) 0%, rgba(120,36,28,0.25) 100%);
    border: 1px solid rgba(192,57,43,0.3);
    color: #e94560;
    font-size: 0.82rem;
    cursor: pointer;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
}
.btn-del-bk:hover {
    background: linear-gradient(135deg, #c0392b 0%, #7b241c 100%);
    color: #fff;
    border-color: rgba(192,57,43,0.6);
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(192,57,43,0.45);
}
.btn-del-bk:active { transform: scale(0.95); }
</style>

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
                    <td>
                        <span class="ref-chip">
                            <i class="fa-solid fa-hashtag" style="font-size:0.7rem;opacity:0.7;"></i>
                            <?= htmlspecialchars(getBookingRef($b)) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($b['customer_email']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($b['package_name']) ?></td>
                    <td><?= htmlspecialchars($b['venue_name'] ?? '—') ?></td>
                    <td style="white-space:nowrap;">
                        <?= formatDate($b['event_date']) ?>
                        <br><small style="color:var(--text-muted);"><?= date('g:i A', strtotime($b['event_time'] ?? '09:00:00')) ?></small>
                    </td>
                    <td>
                        <div class="amount-chip">
                            <span class="cur">₱</span>
                            <span class="amt"><?= number_format((float)$b['total_amount'], 0, '.', ',') ?></span>
                        </div>
                    </td>
                    <td><?= statusBadge($b['status']) ?></td>
                    <td><?= statusBadge($b['payment_status'] ?? $b['status']) ?></td>
                    <td>
                        <div class="bk-actions">
                            <a href="<?= APP_URL ?>/booking_images.php?booking_id=<?= $b['id'] ?>" class="btn-photos" title="View & Upload Photos">
                                <i class="fa-solid fa-camera"></i> Photos
                            </a>
                            <button class="btn-status-upd" data-modal="editStatusModal"
                                    data-edit='<?= json_encode(['id'=>$b['id'], 'status'=>$b['status']]) ?>'>
                                <i class="fa-solid fa-pen-to-square"></i> Status
                            </button>
                            <form method="POST" style="display:inline;margin:0;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn-del-bk" data-confirm="Delete booking <?= htmlspecialchars(getBookingRef($b)) ?>?" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
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
            <h2 class="modal-title"><i class="fa-solid fa-pen-to-square"></i> Update Booking Status</h2>
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
