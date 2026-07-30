<?php
$pageTitle = 'Package Inclusions / Components';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $packageId = (int)($_POST['package_id'] ?? 1);
    $name      = sanitize($_POST['name']        ?? '');
    $desc      = sanitize($_POST['description'] ?? '');
    $cat       = sanitize($_POST['category']    ?? 'venue');
    $price     = (float)($_POST['price']        ?? 0);
    $userId    = $_SESSION['user_id'] ?? 0;

    $allowedCat = ['venue','food','photography','decoration'];
    if (!in_array($cat, $allowedCat)) $cat = 'venue';

    if ($action === 'add') {
        $db->prepare("INSERT INTO package_components (package_id, category, name, description, price) VALUES (?,?,?,?,?)")
           ->execute([$packageId, $cat, $name, $desc, $price]);
        logAudit($userId, 'CREATE', "Added inclusion/component: {$name}", 'package_components');
        setFlash('success', "Inclusion '{$name}' added successfully.");
    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE package_components SET package_id=?, name=?, description=?, category=?, price=? WHERE component_id=?")
           ->execute([$packageId, $name, $desc, $cat, $price, $id]);
        logAudit($userId, 'UPDATE', "Updated component #{$id}", 'package_components');
        setFlash('success', 'Inclusion updated.');
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM package_components WHERE component_id = ?")->execute([$id]);
        logAudit($userId, 'DELETE', "Deleted component #{$id}", 'package_components');
        setFlash('success', 'Inclusion deleted.');
    }
    redirect(APP_URL . '/admin/components.php');
}

$allPackages = $db->query("SELECT package_id AS id, package_name AS name FROM packages ORDER BY package_name ASC")->fetchAll();

$components = $db->query("
    SELECT c.*, c.component_id AS id, p.package_name
    FROM package_components c
    LEFT JOIN packages p ON c.package_id = p.package_id
    ORDER BY p.package_name, c.category, c.name
")->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Package Inclusions & Components</h1>
        <p>Manage specific items, features, and supplies included in craft packages</p>
    </div>
    <button class="btn btn-primary" data-modal="addComponentModal">
        <i class="fa-solid fa-plus"></i> Add Inclusion Item
    </button>
</div>

<?php displayFlash(); ?>

<div class="card">
    <div class="search-bar">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" class="form-control" placeholder="Search inclusions..." data-search-table="compTable">
        </div>
    </div>
    <div class="table-wrapper">
        <table class="table" id="compTable">
            <thead>
                <tr>
                    <th>Inclusion Name</th>
                    <th>Belongs to Package</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($components as $c): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($c['name']) ?></div>
                    </td>
                    <td>
                        <span class="badge badge-secondary">
                            <i class="fa-solid fa-box-open"></i> <?= htmlspecialchars($c['package_name'] ?? 'General') ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $catIcons = ['venue'=>'building','food'=>'utensils','photography'=>'camera','decoration'=>'palette'];
                        $icon = $catIcons[$c['category']] ?? 'star';
                        ?>
                        <span class="badge badge-info">
                            <i class="fa-solid fa-<?= $icon ?>"></i> <?= ucfirst(htmlspecialchars($c['category'])) ?>
                        </span>
                    </td>
                    <td style="font-size:0.82rem;color:var(--text-secondary);">
                        <?= htmlspecialchars($c['description'] ?: '—') ?>
                    </td>
                    <td>
                        <button class="btn btn-warning btn-sm" data-modal="editComponentModal"
                            data-edit='<?= json_encode([
                                'id'          => $c['id'],
                                'package_id'  => $c['package_id'],
                                'name'        => $c['name'],
                                'description' => $c['description'],
                                'category'    => $c['category'],
                                'price'       => $c['price'] ?? 0
                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <i class="fa-solid fa-pen"></i> Edit
                        </button>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this inclusion item?">
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
        <div class="modal-header"><h2 class="modal-title"><i class="fa-solid fa-plus"></i> Add Inclusion Item</h2><button class="modal-close">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Assign to Package <span style="color:var(--accent-red);">*</span></label>
                    <select name="package_id" class="form-control" required>
                        <?php foreach ($allPackages as $pkg): ?>
                            <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Item Name *</label><input type="text" name="name" class="form-control" placeholder="e.g. 5-Course Dinner Catering" required></div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-control" required>
                            <option value="venue">Venue</option>
                            <option value="food">Food & Catering</option>
                            <option value="photography">Photography / Video</option>
                            <option value="decoration" selected>Decoration & Styling</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Description / Details</label><textarea name="description" class="form-control" rows="2" placeholder="Optional details about this inclusion..."></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Inclusion</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editComponentModal">
    <div class="modal">
        <div class="modal-header"><h2 class="modal-title"><i class="fa-solid fa-pen"></i> Edit Inclusion Item</h2><button class="modal-close">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Assign to Package <span style="color:var(--accent-red);">*</span></label>
                    <select name="package_id" class="form-control" required>
                        <?php foreach ($allPackages as $pkg): ?>
                            <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Item Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-control" required>
                            <option value="venue">Venue</option>
                            <option value="food">Food & Catering</option>
                            <option value="photography">Photography / Video</option>
                            <option value="decoration">Decoration & Styling</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Description / Details</label><textarea name="description" class="form-control" rows="2"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="fa-solid fa-floppy-disk"></i> Update Inclusion</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
