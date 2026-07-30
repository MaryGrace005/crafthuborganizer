<?php
$pageTitle = 'Manage Packages';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name        = sanitize($_POST['name']        ?? '');
        $desc        = sanitize($_POST['description'] ?? '');
        $price       = (float)($_POST['price']        ?? 0);
        $maxSlots    = max(1, (int)($_POST['max_slots'] ?? 5));
        $eventType   = sanitize($_POST['event_type']  ?? 'Wedding');
        $imageUrl    = sanitize($_POST['image_url']   ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        $inclusions  = trim($_POST['inclusions'] ?? '');
        $userId      = $_SESSION['user_id'] ?? 0;

        // Auto fallback image based on event type if blank
        if (empty($imageUrl)) {
            $defaultImages = [
                'Wedding'    => 'assets/images/packages/wedding.png',
                'Birthday'   => 'assets/images/packages/birthday.png',
                'Debut'      => 'assets/images/packages/debut.png',
                'Christening'=> 'assets/images/packages/christening.png',
            ];
            $imageUrl = $defaultImages[$eventType] ?? 'assets/images/packages/wedding.png';
        }

        if ($action === 'add') {
            try {
                $db->prepare("INSERT INTO packages (package_name, event_type, base_price, max_slots, description, image_url, status) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$name, $eventType, $price, $maxSlots, $desc, $imageUrl, $status]);
            } catch (PDOException $e) {
                // Fallback if image_url column doesn't exist yet
                $db->prepare("INSERT INTO packages (package_name, event_type, base_price, max_slots, description, status) VALUES (?,?,?,?,?,?)")
                   ->execute([$name, $eventType, $price, $maxSlots, $desc, $status]);
            }
            $packageId = (int)$db->lastInsertId();
            logAudit($userId, 'CREATE', "Created package: {$name}", 'packages');
            setFlash('success', "Package '{$name}' added successfully!");
        } else {
            $packageId = (int)($_POST['id'] ?? 0);
            try {
                $db->prepare("UPDATE packages SET package_name=?, event_type=?, base_price=?, max_slots=?, description=?, image_url=?, status=? WHERE package_id=?")
                   ->execute([$name, $eventType, $price, $maxSlots, $desc, $imageUrl, $status, $packageId]);
            } catch (PDOException $e) {
                $db->prepare("UPDATE packages SET package_name=?, event_type=?, base_price=?, max_slots=?, description=?, status=? WHERE package_id=?")
                   ->execute([$name, $eventType, $price, $maxSlots, $desc, $status, $packageId]);
            }
            logAudit($userId, 'UPDATE', "Updated package #{$packageId}: {$name}", 'packages');
            setFlash('success', "Package updated successfully!");
        }

        // Process inclusions / package_components
        if ($packageId > 0) {
            $db->prepare("DELETE FROM package_components WHERE package_id = ?")->execute([$packageId]);

            if (!empty($inclusions)) {
                $lines = array_filter(array_map('trim', explode("\n", $inclusions)));
                $compStmt = $db->prepare("INSERT INTO package_components (package_id, category, name, description, price) VALUES (?, ?, ?, ?, 0.00)");
                
                $allowedCats = ['venue','food','photography','decoration'];
                foreach ($lines as $line) {
                    if (empty($line)) continue;
                    
                    $cat = 'decoration';
                    $compName = $line;
                    $compDesc = '';

                    if (strpos($line, ':') !== false) {
                        list($possibleCat, $rest) = explode(':', $line, 2);
                        $possibleCat = strtolower(trim($possibleCat));
                        if (in_array($possibleCat, $allowedCats)) {
                            $cat = $possibleCat;
                            $compName = trim($rest);
                        }
                    }

                    if (strpos($compName, '-') !== false) {
                        list($cName, $cDesc) = explode('-', $compName, 2);
                        $compName = trim($cName);
                        $compDesc = trim($cDesc);
                    }

                    $compStmt->execute([$packageId, $cat, $compName, $compDesc]);
                }
            }
        }

        redirect(APP_URL . '/admin/packages.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $userId = $_SESSION['user_id'] ?? 0;
        $db->prepare("UPDATE packages SET status = 'inactive' WHERE package_id = ?")->execute([$id]);
        logAudit($userId, 'DELETE', "Deactivated package #{$id}", 'packages');
        setFlash('success', 'Package deactivated.');
        redirect(APP_URL . '/admin/packages.php');
    }
}

// Fetch packages
$packages = $db->query("
    SELECT p.*, p.package_id AS id, p.package_name AS name, p.base_price AS price, COALESCE(p.max_slots, 5) AS max_slots,
           (SELECT COUNT(*) FROM bookings WHERE package_id = p.package_id AND status != 'Cancelled') AS booking_count
    FROM packages p 
    ORDER BY p.package_id DESC
")->fetchAll();

$defaultImages = [
    'Wedding'    => 'assets/images/packages/wedding.png',
    'Birthday'   => 'assets/images/packages/birthday.png',
    'Debut'      => 'assets/images/packages/debut.png',
    'Christening'=> 'assets/images/packages/christening.png',
];

// Attach components string for each package for edit modal
foreach ($packages as &$p) {
    $cStmt = $db->prepare("SELECT category, name, description FROM package_components WHERE package_id = ? ORDER BY component_id ASC");
    $cStmt->execute([$p['package_id']]);
    $comps = $cStmt->fetchAll();
    
    $incLines = [];
    foreach ($comps as $c) {
        $line = ucfirst($c['category']) . ': ' . $c['name'];
        if (!empty($c['description'])) {
            $line .= ' - ' . $c['description'];
        }
        $incLines[] = $line;
    }
    $p['inclusions_text'] = implode("\n", $incLines);
    $p['components_list'] = $comps;
    $p['image'] = !empty($p['image_url']) ? $p['image_url'] : ($defaultImages[$p['event_type']] ?? 'assets/images/packages/wedding.png');
}
unset($p);
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Packages</h1>
        <p>Manage craft event packages, sample images, and included details</p>
    </div>
    <button class="btn btn-primary" data-modal="addPackageModal">
        <i class="fa-solid fa-plus"></i> Add Package
    </button>
</div>

<?php displayFlash(); ?>

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
                <tr>
                    <th style="width:70px;">Preview</th>
                    <th>Package Name</th>
                    <th>Event Type</th>
                    <th>Base Price</th>
                    <th>Max Slots</th>
                    <th>Included Details</th>
                    <th>Bookings</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $p): ?>
                <tr>
                    <td>
                        <div style="width:54px;height:42px;border-radius:6px;overflow:hidden;background:#1a1a2e;border:1px solid var(--border-color);">
                            <img src="<?= APP_URL ?>/<?= htmlspecialchars($p['image']) ?>"
                                 alt="Sample"
                                 style="width:100%;height:100%;object-fit:cover;"
                                 onerror="this.style.display='none';">
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($p['name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars(substr($p['description'] ?? '',0,50)) ?><?= strlen($p['description'] ?? '') > 50 ? '...' : '' ?></div>
                    </td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($p['event_type'] ?? 'Wedding') ?></span></td>
                    <td style="color:var(--accent-gold);font-weight:700;"><?= formatCurrency($p['price']) ?></td>
                    <td>
                        <span class="badge badge-secondary" style="font-weight:600;">
                            <i class="fa-solid fa-users"></i> <?= (int)$p['max_slots'] ?> slots
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($p['components_list'])): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:220px;">
                                <?php foreach ($p['components_list'] as $comp): ?>
                                    <span style="background:rgba(78,205,196,0.12);color:var(--accent-teal);border:1px solid rgba(78,205,196,0.25);border-radius:12px;padding:2px 8px;font-size:0.72rem;font-weight:500;">
                                        <i class="fa-solid fa-check" style="font-size:0.6rem;"></i> <?= htmlspecialchars($comp['name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:0.8rem;font-style:italic;">No inclusions set</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-secondary"><?= $p['booking_count'] ?></span></td>
                    <td><?= statusBadge($p['status']) ?></td>
                    <td>
                        <button class="btn btn-warning btn-sm"
                            data-modal="editPackageModal"
                            data-edit='<?= json_encode([
                                'id'          => $p['id'],
                                'name'        => $p['name'],
                                'event_type'  => $p['event_type'] ?? 'Wedding',
                                'price'       => $p['price'],
                                'max_slots'   => $p['max_slots'],
                                'image_url'   => $p['image_url'] ?? '',
                                'description' => $p['description'],
                                'status'      => $p['status'],
                                'inclusions'  => $p['inclusions_text']
                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <i class="fa-solid fa-pen"></i> Edit
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

<!-- Add Package Modal -->
<div class="modal-overlay" id="addPackageModal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-plus"></i> Add Package</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Package Name <span style="color:var(--accent-red);">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Deluxe Wedding Package" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Event Type <span style="color:var(--accent-red);">*</span></label>
                        <select name="event_type" class="form-control" required>
                            <option value="Wedding">Wedding</option>
                            <option value="Birthday">Birthday</option>
                            <option value="Debut">Debut</option>
                            <option value="Christening">Christening</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Base Price (₱) <span style="color:var(--accent-red);">*</span></label>
                        <input type="number" name="price" class="form-control" step="0.01" min="0" placeholder="50000" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Max Available Slots <span style="color:var(--accent-red);">*</span></label>
                        <input type="number" name="max_slots" class="form-control" min="1" max="100" value="5" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Image Path / URL <span style="color:var(--text-muted)">(optional)</span></label>
                    <input type="text" name="image_url" class="form-control" placeholder="assets/images/packages/wedding.png">
                    <div class="form-hint">Leave blank to use auto sample picture based on event type.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Package Description</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Brief summary of the package experience..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa-solid fa-list-check" style="color:var(--accent-teal);"></i> Included Details / Inclusions
                    </label>
                    <textarea name="inclusions" class="form-control" rows="4" placeholder="Enter one inclusion per line. Examples:
Venue: Grand Ballroom Setup
Food: 5-Course Plated Dinner
Photography: Premium Photo & Video Coverage
Decoration: Floral & Table Backdrop"></textarea>
                    <div class="form-hint">Enter each included item on a new line. You can optionally prefix with category (e.g. Venue:, Food:, Photography:, Decoration:).</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Package</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Package Modal -->
<div class="modal-overlay" id="editPackageModal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-pen"></i> Edit Package</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Package Name <span style="color:var(--accent-red);">*</span></label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Event Type <span style="color:var(--accent-red);">*</span></label>
                        <select name="event_type" class="form-control" required>
                            <option value="Wedding">Wedding</option>
                            <option value="Birthday">Birthday</option>
                            <option value="Debut">Debut</option>
                            <option value="Christening">Christening</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Base Price (₱) <span style="color:var(--accent-red);">*</span></label>
                        <input type="number" name="price" class="form-control" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Max Available Slots <span style="color:var(--accent-red);">*</span></label>
                        <input type="number" name="max_slots" class="form-control" min="1" max="100" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Image Path / URL</label>
                    <input type="text" name="image_url" class="form-control" placeholder="assets/images/packages/wedding.png">
                </div>
                <div class="form-group">
                    <label class="form-label">Package Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <i class="fa-solid fa-list-check" style="color:var(--accent-teal);"></i> Included Details / Inclusions
                    </label>
                    <textarea name="inclusions" class="form-control" rows="4"></textarea>
                    <div class="form-hint">Enter each included item on a new line. (e.g. Venue: Grand Ballroom)</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="fa-solid fa-floppy-disk"></i> Update Package</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
