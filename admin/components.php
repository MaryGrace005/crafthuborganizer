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

<!-- Component Category Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:0.85rem;color:var(--text-muted);font-weight:700;margin-right:4px;">
        <i class="fa-solid fa-filter"></i> Category:
    </span>
    <button class="comp-filter-tab active" data-cat="all" onclick="filterComponents('all')">
        <i class="fa-solid fa-border-all"></i> All Categories
    </button>
    <button class="comp-filter-tab" data-cat="venue" onclick="filterComponents('venue')">
        🏛️ Venue &amp; Facilities
    </button>
    <button class="comp-filter-tab" data-cat="food" onclick="filterComponents('food')">
        🍽️ Food &amp; Catering
    </button>
    <button class="comp-filter-tab" data-cat="photography" onclick="filterComponents('photography')">
        📷 Photo &amp; Video
    </button>
    <button class="comp-filter-tab" data-cat="decoration" onclick="filterComponents('decoration')">
        🎨 Decor &amp; Styling
    </button>
</div>

<style>
.comp-filter-tab {
    padding: 7px 16px;
    border-radius: 30px;
    font-family: 'Outfit', sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text-secondary);
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    cursor: pointer;
    transition: all 0.25s ease;
}
.comp-filter-tab:hover {
    color: #fff;
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.2);
}
.comp-filter-tab.active {
    background: linear-gradient(135deg, #4ecdc4, #2b938b);
    color: #0f0f1a;
    border-color: var(--accent-teal);
    box-shadow: 0 4px 14px rgba(78,205,196,0.35);
}
</style>

<div class="card">
    <div class="search-bar">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" id="compSearchInput" class="form-control" placeholder="Search inclusions by name, package, or details...">
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
                <tr class="comp-row" data-cat="<?= htmlspecialchars($c['category']) ?>">
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

<script>
let currentCompCat = 'all';

function filterComponents(cat) {
    currentCompCat = cat;
    document.querySelectorAll('.comp-filter-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.cat === cat);
    });
    applyCompFilter();
}

function applyCompFilter() {
    const q = (document.getElementById('compSearchInput').value || '').toLowerCase();
    document.querySelectorAll('#compTable .comp-row').forEach(row => {
        const rowCat = row.dataset.cat;
        const rowText = row.textContent.toLowerCase();

        const matchCat = (currentCompCat === 'all' || rowCat === currentCompCat);
        const matchSearch = (!q || rowText.includes(q));

        row.style.display = (matchCat && matchSearch) ? '' : 'none';
    });
}

document.getElementById('compSearchInput').addEventListener('input', applyCompFilter);
</script>
