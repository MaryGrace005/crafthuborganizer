<?php
$pageTitle = 'Components';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name   = sanitize($_POST['name']        ?? '');
    $desc   = sanitize($_POST['description'] ?? '');
    $cat    = sanitize($_POST['category']    ?? 'General');
    $qty    = (int)($_POST['quantity_available'] ?? 0);
    $unit   = sanitize($_POST['unit']        ?? 'pcs');
    $status = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

    if ($action === 'add') {
        $db->prepare("INSERT INTO components (name, description, category, quantity_available, unit, status) VALUES (?,?,?,?,?,?)")
           ->execute([$name, $desc, $cat, $qty, $unit, $status]);
        logAudit($_SESSION['user_id'], 'CREATE', "Added component: {$name}", 'components');
        setFlash('success', "Component '{$name}' added.");
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE components SET name=?, description=?, category=?, quantity_available=?, unit=?, status=? WHERE id=?")
           ->execute([$name, $desc, $cat, $qty, $unit, $status, $id]);
        logAudit($_SESSION['user_id'], 'UPDATE', "Updated component #{$id}", 'components');
        setFlash('success', 'Component updated.');
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM components WHERE id = ?")->execute([$id]);
        logAudit($_SESSION['user_id'], 'DELETE', "Deleted component #{$id}", 'components');
        setFlash('success', 'Component deleted.');
    }
    redirect(APP_URL . '/admin/components.php');
}

$components = $db->query("SELECT * FROM components ORDER BY category, name")->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div><h1>Components</h1><p>Manage craft supplies and materials</p></div>
    <button class="btn btn-primary" data-modal="addComponentModal">
        <i class="fa-solid fa-plus"></i> Add Component
    </button>
</div>

<div class="card">
    <div class="search-bar">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" class="form-control" placeholder="Search components..." data-search-table="compTable">
        </div>
    </div>
    <div class="table-wrapper">
        <table class="table" id="compTable">
            <thead>
                <tr><th>Name</th><th>Category</th><th>Qty Available</th><th>Unit</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($components as $c): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars(substr($c['description'],0,50)) ?></div>
                    </td>
                    <td><span class="badge badge-secondary"><?= htmlspecialchars($c['category']) ?></span></td>
                    <td>
                        <span style="font-weight:700;color:<?= $c['quantity_available'] < 10 ? 'var(--accent-red)' : 'var(--accent-teal)'; ?>">
                            <?= $c['quantity_available'] ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($c['unit']) ?></td>
                    <td><?= statusBadge($c['status']) ?></td>
                    <td>
                        <button class="btn btn-warning btn-sm" data-modal="editComponentModal"
                            data-edit='<?= json_encode(['id'=>$c['id'],'name'=>$c['name'],'description'=>$c['description'],'category'=>$c['category'],'quantity_available'=>$c['quantity_available'],'unit'=>$c['unit'],'status'=>$c['status']]) ?>'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this component?">
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

<!-- Add Modal -->
<div class="modal-overlay" id="addComponentModal">
    <div class="modal">
        <div class="modal-header"><h2 class="modal-title">Add Component</h2><button class="modal-close">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Category</label><input type="text" name="category" class="form-control" value="General"></div>
                    <div class="form-group"><label class="form-label">Unit</label><input type="text" name="unit" class="form-control" value="pcs"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Qty Available</label><input type="number" name="quantity_available" class="form-control" min="0" value="0"></div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editComponentModal">
    <div class="modal">
        <div class="modal-header"><h2 class="modal-title">Edit Component</h2><button class="modal-close">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Category</label><input type="text" name="category" class="form-control"></div>
                    <div class="form-group"><label class="form-label">Unit</label><input type="text" name="unit" class="form-control"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Qty Available</label><input type="number" name="quantity_available" class="form-control" min="0"></div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning">Update</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
