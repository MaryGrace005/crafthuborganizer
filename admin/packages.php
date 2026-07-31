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
    <button class="btn btn-primary btn-add-package" data-modal="addPackageModal">
        <i class="fa-solid fa-plus"></i> Add Package
    </button>
</div>

<style>
/* ── Add Package CTA ── */
.btn-add-package {
    padding: 13px 28px;
    font-size: 0.95rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    background: linear-gradient(135deg, #e94560 0%, #c0392b 60%, #a93226 100%);
    border-radius: 14px;
    box-shadow:
        0 6px 24px rgba(233,69,96,0.5),
        0 0 0 0 rgba(233,69,96,0.4);
    animation: pulse-ring 2.4s cubic-bezier(0.4,0,0.6,1) infinite;
    border: 1px solid rgba(255,255,255,0.15);
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
}
.btn-add-package:hover {
    transform: translateY(-4px) scale(1.04);
    box-shadow:
        0 14px 36px rgba(233,69,96,0.65),
        0 0 0 4px rgba(233,69,96,0.18);
    animation: none;
}
.btn-add-package i {
    background: rgba(255,255,255,0.25);
    border-radius: 50%;
    width: 22px; height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
    transition: transform 0.3s ease;
}
.btn-add-package:hover i { transform: rotate(90deg); }

@keyframes pulse-ring {
    0%,100% { box-shadow: 0 6px 24px rgba(233,69,96,0.5), 0 0 0 0 rgba(233,69,96,0.35); }
    50%      { box-shadow: 0 6px 24px rgba(233,69,96,0.5), 0 0 0 8px rgba(233,69,96,0); }
}

/* ── Action buttons in table ── */
.pkg-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── Slots chip ── */
.slots-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px 6px 8px;
    background: linear-gradient(135deg, rgba(78,205,196,0.12) 0%, rgba(26,158,150,0.08) 100%);
    border: 1px solid rgba(78,205,196,0.28);
    border-radius: 30px;
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    font-size: 0.82rem;
    color: var(--accent-teal);
    white-space: nowrap;
    box-shadow: 0 0 12px rgba(78,205,196,0.08), inset 0 1px 0 rgba(255,255,255,0.06);
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    cursor: default;
}
.slots-chip:hover {
    background: linear-gradient(135deg, rgba(78,205,196,0.2) 0%, rgba(26,158,150,0.14) 100%);
    border-color: rgba(78,205,196,0.5);
    box-shadow: 0 4px 16px rgba(78,205,196,0.25);
    transform: translateY(-1px);
}
.slots-icon {
    width: 22px; height: 22px;
    background: rgba(78,205,196,0.18);
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    flex-shrink: 0;
}
.slots-count {
    font-size: 1rem;
    font-weight: 800;
    color: #fff;
    line-height: 1;
}
.slots-label {
    font-size: 0.74rem;
    font-weight: 600;
    color: var(--accent-teal);
    opacity: 0.85;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

/* ── Price chip ── */
.price-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px 6px 6px;
    background: linear-gradient(135deg, rgba(245,166,35,0.12) 0%, rgba(230,126,34,0.07) 100%);
    border: 1px solid rgba(245,166,35,0.3);
    border-radius: 30px;
    font-family: 'Outfit', sans-serif;
    white-space: nowrap;
    box-shadow: 0 0 14px rgba(245,166,35,0.08), inset 0 1px 0 rgba(255,255,255,0.06);
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    cursor: default;
}
.price-chip:hover {
    background: linear-gradient(135deg, rgba(245,166,35,0.22) 0%, rgba(230,126,34,0.14) 100%);
    border-color: rgba(245,166,35,0.55);
    box-shadow: 0 4px 18px rgba(245,166,35,0.28);
    transform: translateY(-1px);
}
.price-currency {
    width: 22px; height: 22px;
    background: rgba(245,166,35,0.2);
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 800;
    color: var(--accent-gold);
    flex-shrink: 0;
    line-height: 1;
}
.price-amount {
    font-size: 0.95rem;
    font-weight: 800;
    color: #fff;
    line-height: 1;
    letter-spacing: 0.01em;
}

.btn-edit-pkg {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    border-radius: 10px;
    border: 1px solid rgba(245,166,35,0.35);
    background: linear-gradient(135deg, #f5a623 0%, #e67e22 100%);
    color: #fff;
    box-shadow: 0 3px 12px rgba(245,166,35,0.4), inset 0 1px 0 rgba(255,255,255,0.2);
    cursor: pointer;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    white-space: nowrap;
    position: relative;
    overflow: hidden;
}
.btn-edit-pkg::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(105deg, transparent 40%, rgba(255,255,255,0.15) 50%, transparent 60%);
    background-size: 200% 100%;
    opacity: 0;
    transition: opacity 0.2s;
}
.btn-edit-pkg:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(245,166,35,0.55), inset 0 1px 0 rgba(255,255,255,0.2);
    background: linear-gradient(135deg, #ffc04a 0%, #f08c2e 100%);
}
.btn-edit-pkg:hover::after { opacity: 1; animation: btn-shimmer 0.55s ease; }
.btn-edit-pkg:active { transform: translateY(0) scale(0.97); }

.btn-deactivate-pkg {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px; height: 34px;
    border-radius: 10px;
    border: 1px solid rgba(192,57,43,0.35);
    background: linear-gradient(135deg, rgba(192,57,43,0.2) 0%, rgba(120,36,28,0.3) 100%);
    color: #e94560;
    font-size: 0.88rem;
    cursor: pointer;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.05);
}
.btn-deactivate-pkg:hover {
    background: linear-gradient(135deg, #c0392b 0%, #7b241c 100%);
    color: #fff;
    border-color: rgba(192,57,43,0.6);
    transform: translateY(-3px);
    box-shadow: 0 6px 18px rgba(192,57,43,0.5), inset 0 1px 0 rgba(255,255,255,0.1);
}
.btn-deactivate-pkg:active { transform: scale(0.95); }
</style>

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
                    <td>
                        <div class="price-chip">
                            <span class="price-currency">₱</span>
                            <span class="price-amount"><?= number_format((float)$p['price'], 0, '.', ',') ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="slots-chip">
                            <span class="slots-icon"><i class="fa-solid fa-users"></i></span>
                            <span class="slots-count"><?= (int)$p['max_slots'] ?></span>
                            <span class="slots-label">Slots</span>
                        </div>
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
                        <div class="pkg-actions">
                            <button class="btn-edit-pkg"
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
                                <i class="fa-solid fa-pen-to-square"></i> Edit
                            </button>
                            <form method="POST" style="display:inline;margin:0;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn-deactivate-pkg" data-confirm="Deactivate this package?" title="Deactivate">
                                    <i class="fa-solid fa-ban"></i>
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
