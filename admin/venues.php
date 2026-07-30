<?php
$pageTitle = 'Venues';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name   = sanitize($_POST['name']         ?? '');
    $loc    = sanitize($_POST['address']      ?? $_POST['location'] ?? '');
    $cap    = (int)($_POST['capacity']         ?? 50);
    $status = in_array($_POST['status'] ?? '', ['available','unavailable']) ? $_POST['status'] : 'available';
    $userId = $_SESSION['user_id'] ?? 0;

    if ($action === 'add') {
        $db->prepare("INSERT INTO venues (venue_name, capacity, location, availability_status) VALUES (?,?,?,?)")
           ->execute([$name, $cap, $loc, $status]);
        logAudit($userId, 'CREATE', "Added venue: {$name}", 'venues');
        setFlash('success', "Venue '{$name}' added.");
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE venues SET venue_name=?, capacity=?, location=?, availability_status=? WHERE venue_id=?")
           ->execute([$name, $cap, $loc, $status, $id]);
        logAudit($userId, 'UPDATE', "Updated venue #{$id}", 'venues');
        setFlash('success', 'Venue updated.');
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM venues WHERE venue_id = ?")->execute([$id]);
        logAudit($userId, 'DELETE', "Deleted venue #{$id}", 'venues');
        setFlash('success', 'Venue deleted.');
    }
    redirect(APP_URL . '/admin/venues.php');
}

$venues = $db->query("SELECT v.*, v.venue_id AS id, v.venue_name AS name, v.location AS address, v.availability_status AS status, (SELECT COUNT(*) FROM bookings WHERE venue_id = v.venue_id) AS booking_count FROM venues v ORDER BY venue_name")->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div><h1>Venues</h1><p>Manage event locations</p></div>
    <button class="btn btn-primary" data-modal="addVenueModal"><i class="fa-solid fa-plus"></i> Add Venue</button>
</div>

<div class="grid-auto" style="margin-bottom:20px;">
    <?php foreach ($venues as $v): ?>
    <div class="card" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
            <div>
                <div style="font-size:1rem;font-weight:700;"><?= htmlspecialchars($v['name']) ?></div>
                <?= statusBadge($v['status']) ?>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-warning btn-sm" data-modal="editVenueModal"
                    data-edit='<?= json_encode(['id'=>$v['id'],'name'=>$v['name'],'address'=>$v['address'],'capacity'=>$v['capacity'],'status'=>$v['status']]) ?>'>
                    <i class="fa-solid fa-pen"></i>
                </button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $v['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this venue?"><i class="fa-solid fa-trash"></i></button>
                </form>
            </div>
        </div>

        <div style="display:grid;gap:8px;font-size:0.85rem;">
            <div style="display:flex;gap:8px;color:var(--text-secondary);">
                <i class="fa-solid fa-location-dot" style="color:var(--accent-red);width:14px;"></i>
                <?= htmlspecialchars($v['address'] ?: 'No location specified') ?>
            </div>
            <div style="display:flex;gap:8px;color:var(--text-secondary);">
                <i class="fa-solid fa-users" style="color:var(--accent-teal);width:14px;"></i>
                Capacity: <?= (int)$v['capacity'] ?> guests
            </div>
            <div style="display:flex;gap:8px;color:var(--text-secondary);">
                <i class="fa-solid fa-calendar-check" style="color:var(--accent-purple);width:14px;"></i>
                <?= (int)$v['booking_count'] ?> booking(s)
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addVenueModal">
    <div class="modal">
        <div class="modal-header"><h2 class="modal-title">Add Venue</h2><button class="modal-close">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Venue Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Location / Address *</label><textarea name="address" class="form-control" rows="2" required></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Capacity (guests)</label><input type="number" name="capacity" class="form-control" value="50" min="1" required></div>
                    <div class="form-group"><label class="form-label">Availability</label>
                        <select name="status" class="form-control"><option value="available">Available</option><option value="unavailable">Unavailable</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Venue</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editVenueModal">
    <div class="modal">
        <div class="modal-header"><h2 class="modal-title">Edit Venue</h2><button class="modal-close">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Venue Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Location / Address *</label><textarea name="address" class="form-control" rows="2" required></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Capacity (guests)</label><input type="number" name="capacity" class="form-control" min="1" required></div>
                    <div class="form-group"><label class="form-label">Availability</label>
                        <select name="status" class="form-control"><option value="available">Available</option><option value="unavailable">Unavailable</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning">Update Venue</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
