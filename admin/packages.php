<?php
$pageTitle = 'Manage Packages';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name     = sanitize($_POST['name']        ?? '');
        $desc     = sanitize($_POST['description'] ?? '');
        $price    = (float)($_POST['price']        ?? 0);
        $cap      = (int)($_POST['capacity']       ?? 1);
        $dur      = (int)($_POST['duration_hours'] ?? 2);
        $status   = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if ($action === 'add') {
            $db->prepare("INSERT INTO packages (name, description, price, capacity, duration_hours, status) VALUES (?,?,?,?,?,?)")
               ->execute([$name, $desc, $price, $cap, $dur, $status]);
            $newId = $db->lastInsertId();
            logAudit($_SESSION['user_id'], 'CREATE', "Created package: {$name}", 'packages');
            setFlash('success', "Package '{$name}' added successfully!");
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE packages SET name=?, description=?, price=?, capacity=?, duration_hours=?, status=? WHERE id=?")
               ->execute([$name, $desc, $price, $cap, $dur, $status, $id]);
            logAudit($_SESSION['user_id'], 'UPDATE', "Updated package #{$id}: {$name}", 'packages');
            setFlash('success', "Package updated successfully!");
        }
        redirect(APP_URL . '/admin/packages.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE packages SET status = 'inactive' WHERE id = ?")->execute([$id]);
        logAudit($_SESSION['user_id'], 'DELETE', "Deactivated package #{$id}", 'packages');
        setFlash('success', 'Package deactivated.');
        redirect(APP_URL . '/admin/packages.php');
    }
}

$packages = $db->query("SELECT p.*, (SELECT COUNT(*) FROM bookings WHERE package_id = p.id) AS booking_count FROM packages ORDER BY created_at DESC")->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div><h1>Packages</h1><p>Manage craft event packages</p></div>
    <button class="btn btn-primary" data-modal="addPackageModal">
        <i class="fa-solid fa-plus"></i> Add Package
    </button>
</div>

<div class="card">
    <div class="search-bar">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" class="form-control" placeholder="Search packages..." data-search-table="pkgTable">
        </div>
    </div>

    <div class="table-wrapper">
        <table class="table" id="pkgTable">
            <thead>
                <tr><th>Name</th><th>Price</th><th>Capacity</th><th>Duration</th><th>Bookings</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $p): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars(substr($p['description'],0,60)) ?>...</div>
                    </td>
                    <td style="color:var(--accent-gold);font-weight:700;"><?= formatCurrency($p['price']) ?></td>
                    <td><?= $p['capacity'] ?> guests</td>
                    <td><?= $p['duration_hours'] ?>h</td>
                    <td><span class="badge badge-info"><?= $p['booking_count'] ?></span></td>
                    <td><?= statusBadge($p['status']) ?></td>
                    <td>
                        <button class="btn btn-warning btn-sm"
                            data-modal="editPackageModal"
                            data-edit='<?= json_encode(['id'=>$p['id'],'name'=>$p['name'],'description'=>$p['description'],'price'=>$p['price'],'capacity'=>$p['capacity'],'duration_hours'=>$p['duration_hours'],'status'=>$p['status']]) ?>'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Deactivate this package?">
                                <i class="fa-solid fa-ban"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div class="modal-overlay" id="addPackageModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-plus"></i> Add Package</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Price (₱) *</label><input type="number" name="price" class="form-control" step="0.01" min="0" required></div>
                    <div class="form-group"><label class="form-label">Capacity</label><input type="number" name="capacity" class="form-control" min="1" value="10"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Duration (hours)</label><input type="number" name="duration_hours" class="form-control" min="1" value="2"></div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Package</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editPackageModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-pen"></i> Edit Package</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Price (₱) *</label><input type="number" name="price" class="form-control" step="0.01" min="0" required></div>
                    <div class="form-group"><label class="form-label">Capacity</label><input type="number" name="capacity" class="form-control" min="1"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Duration (hours)</label><input type="number" name="duration_hours" class="form-control" min="1"></div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="fa-solid fa-floppy-disk"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
