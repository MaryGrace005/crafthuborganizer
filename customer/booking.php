<?php
$pageTitle = 'Book a Package';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);

$db         = getDB();
$user       = getCurrentUser();
$packageId  = (int)($_GET['package_id'] ?? 0);

// Load packages and venues for form
$packages = $db->query("SELECT * FROM packages WHERE status = 'active' ORDER BY name")->fetchAll();
$venues   = $db->query("SELECT * FROM venues WHERE status = 'available' ORDER BY name")->fetchAll();

$selectedPackage = null;
if ($packageId) {
    $stmt = $db->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active'");
    $stmt->execute([$packageId]);
    $selectedPackage = $stmt->fetch();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pkgId    = (int)($_POST['package_id'] ?? 0);
    $venueId  = (int)($_POST['venue_id']   ?? 0) ?: null;
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
        $pkgStmt = $db->prepare("SELECT price FROM packages WHERE id = ?");
        $pkgStmt->execute([$pkgId]);
        $pkg = $pkgStmt->fetch();

        $venuePrice = 0;
        if ($venueId) {
            $vStmt = $db->prepare("SELECT price_per_day FROM venues WHERE id = ?");
            $vStmt->execute([$venueId]);
            $v = $vStmt->fetch();
            $venuePrice = $v ? (float)$v['price_per_day'] : 0;
        }

        $total = (float)$pkg['price'] + $venuePrice;
        $ref   = generateBookingRef();

        $ins = $db->prepare("
            INSERT INTO bookings (booking_reference, customer_id, package_id, venue_id, event_date, event_time, num_guests, total_amount, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$ref, $user['id'], $pkgId, $venueId, $eventDate, $eventTime, $numGuests, $total, $notes]);

        logAudit($user['id'], 'BOOKING', "Created booking {$ref} for package #{$pkgId}", 'bookings');
        setFlash('success', "Booking submitted! Reference: {$ref}. Please wait for confirmation.");
        redirect(APP_URL . '/customer/bookings.php');
    }
}

// Build JS price maps
$pkgPriceMap   = [];
$venuePriceMap = [];
foreach ($packages as $p)  $pkgPriceMap[$p['id']]   = $p['price'];
foreach ($venues   as $v)  $venuePriceMap[$v['id']]  = $v['price_per_day'];
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<script>
    window.packagePrices = <?= json_encode($pkgPriceMap) ?>;
    window.venuePrices   = <?= json_encode($venuePriceMap) ?>;
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
                <select id="package_id" name="package_id" class="form-control" required>
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
                <select id="venue_id" name="venue_id" class="form-control">
                    <option value="">-- No specific venue --</option>
                    <?php foreach ($venues as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= ($_POST['venue_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['name']) ?> — +<?= formatCurrency($v['price_per_day']) ?>
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
            <div style="text-align:center;padding:20px 0;">
                <div style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:8px;">Estimated Total</div>
                <div id="total_display" style="font-family:'Outfit',sans-serif;font-size:2.5rem;font-weight:800;color:var(--accent-gold);">
                    ₱ 0.00
                </div>
                <input type="hidden" id="total_amount" name="total_amount" value="0">
                <div style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">Package price + venue fee</div>
            </div>

            <div style="padding:16px;background:rgba(78,205,196,0.05);border-radius:var(--radius-md);border:1px solid rgba(78,205,196,0.15);">
                <p style="font-size:0.83rem;color:var(--text-secondary);line-height:1.6;">
                    <i class="fa-solid fa-info-circle" style="color:var(--accent-teal);"></i>
                    Your booking will be reviewed and confirmed by our team. Payment can be made upon confirmation.
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
