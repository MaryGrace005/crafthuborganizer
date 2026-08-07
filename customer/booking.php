<?php
$pageTitle = 'Book a Package';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);
requireApproved();

$db         = getDB();
$user       = getCurrentUser();
$packageId  = (int)($_GET['package_id'] ?? 0);

// Load packages and venues for form
$packages = $db->query("SELECT package_id AS id, package_id, package_name AS name, package_name, base_price AS price, base_price, event_type, description, status FROM packages WHERE status = 'active' ORDER BY package_name")->fetchAll();
$venues   = $db->query("SELECT venue_id AS id, venue_id, venue_name AS name, venue_name, capacity, location, availability_status FROM venues WHERE availability_status = 'available' ORDER BY venue_name")->fetchAll();

// Build JS maps
$pkgPriceMap      = [];
$pkgInclusionsMap = [];
$venuePriceMap    = [];

foreach ($packages as $p) {
    $pkgPriceMap[$p['id']] = $p['price'];
    
    // Fetch inclusions for JS
    $cStmt = $db->prepare("SELECT category, name, description FROM package_components WHERE package_id = ? ORDER BY category, name");
    $cStmt->execute([$p['id']]);
    $comps = $cStmt->fetchAll();
    
    $pkgInclusionsMap[$p['id']] = array_map(function($c) {
        return [
            'name'        => $c['name'],
            'category'    => $c['category'],
            'description' => $c['description']
        ];
    }, $comps);
}

foreach ($venues as $v) {
    $venuePriceMap[$v['id']] = 0;
}

$selectedPackage = null;
if ($packageId) {
    $stmt = $db->prepare("SELECT package_id AS id, package_id, package_name AS name, base_price AS price FROM packages WHERE package_id = ? AND status = 'active'");
    $stmt->execute([$packageId]);
    $selectedPackage = $stmt->fetch();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pkgId     = (int)($_POST['package_id'] ?? 0);
    $venueId   = (int)($_POST['venue_id']   ?? 0) ?: null;
    $eventDate = sanitize($_POST['event_date'] ?? '');
    $eventTime = sanitize($_POST['event_time'] ?? '09:00');
    $numGuests = (int)($_POST['num_guests']  ?? 1);
    $notes     = sanitize($_POST['notes']    ?? '');

    if (!$pkgId)            $errors[] = 'Please select a package.';
    if (empty($eventDate))  $errors[] = 'Please select an event date.';
    if ($eventDate < date('Y-m-d', strtotime('+1 day'))) $errors[] = 'Event date must be at least tomorrow.';
    if ($numGuests < 1)     $errors[] = 'Number of guests must be at least 1.';

    if (empty($errors)) {
        // Calculate total
        $pkgStmt = $db->prepare("SELECT base_price, event_type FROM packages WHERE package_id = ?");
        $pkgStmt->execute([$pkgId]);
        $pkg = $pkgStmt->fetch();

        $total = (float)($pkg['base_price'] ?? 0);
        $eventType = $pkg['event_type'] ?? 'Wedding';

        $ref = generateBookingRef();
        $userId = $user['user_id'] ?? $user['id'];

        try {
            $ins = $db->prepare("
                INSERT INTO bookings
                    (booking_reference, customer_id, package_id, venue_id, event_date, event_time, event_type, guest_count, total_amount, status, notes)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
            ");
            $ins->execute([
                $ref,
                $userId,
                $pkgId,
                $venueId,   // NULL is fine — column is now nullable in DB
                $eventDate,
                $eventTime,
                $eventType,
                $numGuests,
                $total,
                $notes
            ]);
        } catch (PDOException $e) {
            $errors[] = 'Booking could not be saved: ' . $e->getMessage();
        }

        if (empty($errors)) {
            logAudit($userId, 'BOOKING', "Created booking #{$ref} for package #{$pkgId}", 'bookings');
            setFlash('success', "Booking submitted successfully! Please wait for confirmation.");
            redirect(APP_URL . '/customer/bookings.php');
        }
    }
}
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<script>
    window.packagePrices      = <?= json_encode($pkgPriceMap) ?>;
    window.packageInclusions  = <?= json_encode($pkgInclusionsMap) ?>;
    window.venuePrices        = <?= json_encode($venuePriceMap) ?>;
</script>

<div class="page-header">
    <div>
        <h1>Book a Package</h1>
        <p>Fill in the details below to reserve your craft experience</p>
    </div>
    <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to Packages
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <span class="alert-icon">✗</span>
        <div><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
    </div>
<?php endif; ?>

<div class="grid-2" style="align-items:start;">
    <!-- Booking Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-calendar-plus"></i> Booking Details</h2>
        </div>

        <form method="POST" action="">

            <div class="form-group">
                <label class="form-label" for="package_id">Select Package <span style="color:var(--accent-red);">*</span></label>
                <select id="package_id" name="package_id" class="form-control" required onchange="updateBookingSummary()">
                    <option value="">-- Choose a package --</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= $p['id'] ?>"
                                <?= ($selectedPackage && $selectedPackage['id'] == $p['id']) || ($_POST['package_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?> — <?= formatCurrency($p['price']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="venue_id">Select Venue <span style="color:var(--text-muted)">(optional)</span></label>
                <select id="venue_id" name="venue_id" class="form-control" onchange="updateBookingSummary()">
                    <option value="">-- No specific venue --</option>
                    <?php foreach ($venues as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= ($_POST['venue_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['name']) ?> (Capacity: <?= (int)($v['capacity'] ?? 0) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="event_date">Event Date <span style="color:var(--accent-red);">*</span></label>
                    <input type="date" id="event_date" name="event_date" class="form-control"
                           min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                           value="<?= htmlspecialchars($_POST['event_date'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="event_time">Event Time</label>
                    <input type="time" id="event_time" name="event_time" class="form-control"
                           value="<?= htmlspecialchars($_POST['event_time'] ?? '09:00') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="num_guests">Number of Guests <span style="color:var(--accent-red);">*</span></label>
                <input type="number" id="num_guests" name="num_guests" class="form-control"
                       min="1" max="200" value="<?= (int)($_POST['num_guests'] ?? 1) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Special Notes <span style="color:var(--text-muted)">(optional)</span></label>
                <textarea id="notes" name="notes" class="form-control" rows="3"
                          placeholder="Any special requests or details..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">
                <i class="fa-solid fa-calendar-check"></i> Submit Booking
            </button>
        </form>
    </div>

    <!-- Summary Panel -->
    <div>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-receipt"></i> Booking Summary</h2>
            </div>
            <div style="text-align:center;padding:16px 0 10px;">
                <div style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:4px;">Estimated Total</div>
                <div id="total_display" style="font-family:'Outfit',sans-serif;font-size:2.4rem;font-weight:800;color:var(--accent-gold);">
                    ₱ 0.00
                </div>
                <input type="hidden" id="total_amount" name="total_amount" value="0">
                <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;">Base package price</div>
            </div>

            <!-- Inclusions Box -->
            <div id="inclusions_box" style="display:none;margin-bottom:16px;padding:14px;background:rgba(78,205,196,0.06);border-radius:var(--radius-sm);border:1px solid rgba(78,205,196,0.2);">
                <div style="font-weight:700;font-size:0.82rem;color:var(--accent-teal);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">
                    <i class="fa-solid fa-list-check"></i> Package Inclusions:
                </div>
                <ul id="inclusions_list" style="list-style:none;padding:0;margin:0;display:grid;gap:6px;font-size:0.82rem;color:var(--text-primary);">
                </ul>
            </div>

            <div style="padding:14px;background:rgba(255,255,255,0.03);border-radius:var(--radius-md);border:1px solid var(--border-color);">
                <p style="font-size:0.82rem;color:var(--text-secondary);line-height:1.6;margin:0;">
                    <i class="fa-solid fa-info-circle" style="color:var(--accent-teal);"></i>
                    Your booking will be reviewed and confirmed by our team.
                </p>
            </div>
        </div>

        <div class="card mt-2">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;"><i class="fa-solid fa-user"></i> Booking For</h2>
            </div>
            <div style="font-size:0.9rem;color:var(--text-secondary);line-height:1.8;">
                <div><strong style="color:var(--text-primary);"><?= htmlspecialchars($user['name']) ?></strong></div>
                <div><?= htmlspecialchars($user['email']) ?></div>
                <div><?= htmlspecialchars($user['phone'] ?? 'No phone on file') ?></div>
            </div>
        </div>
    </div>
</div>

<script>
function updateBookingSummary() {
    var pkgId = document.getElementById('package_id').value;
    var totalDisplay = document.getElementById('total_display');
    var incBox = document.getElementById('inclusions_box');
    var incList = document.getElementById('inclusions_list');

    var price = window.packagePrices[pkgId] || 0;
    totalDisplay.innerText = '₱ ' + parseFloat(price).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    if (pkgId && window.packageInclusions[pkgId] && window.packageInclusions[pkgId].length > 0) {
        var items = window.packageInclusions[pkgId];
        var html = '';
        items.forEach(function(item) {
            html += '<li style="display:flex;align-items:flex-start;gap:6px;">';
            html += '<i class="fa-solid fa-check" style="color:var(--accent-teal);margin-top:3px;font-size:0.75rem;"></i>';
            html += '<div><strong>' + escapeHtml(item.name) + '</strong>';
            if (item.description) {
                html += ' <span style="color:var(--text-muted);font-size:0.75rem;">(' + escapeHtml(item.description) + ')</span>';
            }
            html += '</div></li>';
        });
        incList.innerHTML = html;
        incBox.style.display = 'block';
    } else if (pkgId) {
        incList.innerHTML = '<li style="color:var(--text-muted);font-style:italic;">Standard craft package inclusions.</li>';
        incBox.style.display = 'block';
    } else {
        incBox.style.display = 'none';
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', function() {
    updateBookingSummary();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
