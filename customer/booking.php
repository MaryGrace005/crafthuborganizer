<?php
$pageTitle = 'Book a Package';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);
requireApproved();

$db         = getDB();
$user       = getCurrentUser();
$userId     = $user['user_id'] ?? $user['id'];
$ongoingBookings     = getCustomerOngoingBookings($userId);
$hasOngoingPayment   = !empty($ongoingBookings);
$totalOngoingBalance = array_sum(array_column($ongoingBookings, 'calculated_balance'));

$packageId  = (int)($_GET['package_id'] ?? 0);
// Pre-fill from availability calendar links: ?venue_id=X&date=YYYY-MM-DD
$preVenueId = (int)($_GET['venue_id'] ?? 0);
$preDate    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';


// Load packages and venues for form
$packages = getActivePackages();
$venues   = $db->query("SELECT venue_id AS id, venue_id, venue_name AS name, venue_name, capacity, location, availability_status FROM venues WHERE availability_status = 'available' ORDER BY venue_name")->fetchAll();

// Build JS maps
$pkgPriceMap      = [];
$pkgDiscountMap   = [];
$pkgInclusionsMap = [];

foreach ($packages as $p) {
    $pkgPriceMap[$p['id']]    = $p['price'];
    $pkgDiscountMap[$p['id']] = [
        'full_payment_discount_percent' => (float)($p['full_payment_discount_percent'] ?? 10),
        'downpayment_discount_percent'  => (float)($p['downpayment_discount_percent']  ?? 5),
        'downpayment_percent'           => (float)($p['downpayment_percent']            ?? 50),
    ];
    $cStmt = $db->prepare("SELECT category, name, description FROM package_components WHERE package_id = ? ORDER BY category, name");
    $cStmt->execute([$p['id']]);
    $comps = $cStmt->fetchAll();
    $pkgInclusionsMap[$p['id']] = array_map(function($c) {
        return ['name' => $c['name'], 'category' => $c['category'], 'description' => $c['description']];
    }, $comps);
}

// Pre-build booked dates map for each venue (for JS date picker)
$venueBookedDatesMap = [];
foreach ($venues as $v) {
    $venueBookedDatesMap[$v['id']] = getVenueBookedDates($v['id']);
}

$selectedPackage = null;
if ($packageId) {
    $stmt = $db->prepare("SELECT package_id AS id, package_id, package_name AS name, base_price AS price FROM packages WHERE package_id = ? AND status = 'active'");
    $stmt->execute([$packageId]);
    $selectedPackage = $stmt->fetch();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ongoing Payment Block - Cannot book another package if an active balance exists
    if ($hasOngoingPayment) {
        setFlash('error', 'You cannot book another package while you have an ongoing payment. All previous bookings must be fully paid first.');
        redirect(APP_URL . '/customer/bookings.php');
    }

    $pkgId     = (int)($_POST['package_id'] ?? 0);
    $venueId   = (int)($_POST['venue_id']   ?? 0) ?: null;
    $eventDate = sanitize($_POST['event_date'] ?? '');
    $eventTime = sanitize($_POST['event_time'] ?? '09:00');
    $numGuests = (int)($_POST['num_guests']  ?? 1);
    $notes     = sanitize($_POST['notes']    ?? '');

    // Basic validation
    if (!$pkgId)            $errors[] = 'Please select a package.';
    if (empty($eventDate))  $errors[] = 'Please select an event date.';
    if ($eventDate < date('Y-m-d', strtotime('+1 day'))) $errors[] = 'Event date must be at least tomorrow.';
    if ($numGuests < 1)     $errors[] = 'Number of guests must be at least 1.';

    // ── Package slot availability check (server-side — cannot be bypassed) ──
    if ($pkgId && empty($errors)) {
        $slotStmt = $db->prepare("
            SELECT COALESCE(max_slots, 5) AS max_slots,
                   (SELECT COUNT(*) FROM bookings WHERE package_id = ? AND status IN ('Pending','Confirmed','Paid')) AS active_bookings
            FROM packages WHERE package_id = ? AND status = 'active'
        ");
        $slotStmt->execute([$pkgId, $pkgId]);
        $slotRow = $slotStmt->fetch();
        if (!$slotRow) {
            $errors[] = 'Selected package is no longer available.';
        } elseif ((int)$slotRow['active_bookings'] >= (int)$slotRow['max_slots']) {
            $errors[] = 'Sorry, this package is fully booked. Please choose a different package.';
        }
    }

    // ── Venue availability check (server-side — cannot be bypassed) ──
    if (empty($errors) && $venueId !== null) {
        if (!isVenueDateAvailable($venueId, $eventDate)) {
            // Look up venue name for a friendly message
            $vStmt = $db->prepare("SELECT venue_name FROM venues WHERE venue_id = ?");
            $vStmt->execute([$venueId]);
            $vRow  = $vStmt->fetch();
            $vName = $vRow ? htmlspecialchars($vRow['venue_name']) : 'The selected venue';
            $errors[] = "{$vName} is already booked on " . date('F j, Y', strtotime($eventDate)) . '. Please choose a different date or venue.';
        }
    }

    $paymentPlan = in_array($_POST['payment_plan'] ?? '', ['full','downpayment']) ? $_POST['payment_plan'] : 'full';

    if (empty($errors)) {
        $pkgStmt = $db->prepare("SELECT base_price, event_type,
            COALESCE(full_payment_discount_percent,10) AS full_payment_discount_percent,
            COALESCE(downpayment_discount_percent,5)  AS downpayment_discount_percent,
            COALESCE(downpayment_percent,50)          AS downpayment_percent
            FROM packages WHERE package_id = ?");
        $pkgStmt->execute([$pkgId]);
        $pkg = $pkgStmt->fetch();

        $pricing   = calculatePackagePricing(
            (float)$pkg['base_price'],
            (float)$pkg['full_payment_discount_percent'],
            (float)$pkg['downpayment_discount_percent'],
            (float)$pkg['downpayment_percent'],
            $paymentPlan
        );
        $total      = $pricing['discounted_total'];
        $downAmt    = $pricing['downpayment_due'];
        $eventType  = $pkg['event_type'] ?? 'Wedding';

        $ref    = generateBookingRef();
        $userId = $user['user_id'] ?? $user['id'];
        // Compute payment due date (7 days before event date, or 3 days from now / 1 day before event)
        $dueDate = date('Y-m-d', max(strtotime('+1 day'), strtotime('-7 days', strtotime($eventDate))));

        try {
            // Ensure bookings table has discount/payment_plan columns
            try { $db->exec("ALTER TABLE bookings ADD COLUMN payment_plan VARCHAR(20) NOT NULL DEFAULT 'full'"); } catch(PDOException $e) {}
            try { $db->exec("ALTER TABLE bookings ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00"); } catch(PDOException $e) {}
            try { $db->exec("ALTER TABLE bookings ADD COLUMN downpayment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00"); } catch(PDOException $e) {}
            $ins = $db->prepare("
                INSERT INTO bookings
                    (booking_reference, customer_id, package_id, venue_id, event_date, payment_due_date, event_time, event_type, guest_count, total_amount, payment_plan, discount_amount, downpayment_amount, status, notes)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
            ");
            $ins->execute([
                $ref, $userId, $pkgId, $venueId,
                $eventDate, $dueDate, $eventTime, $eventType, $numGuests,
                $total, $paymentPlan, $pricing['discount_amount'], $downAmt,
                $notes
            ]);
        } catch (PDOException $e) {
            // Catch the DB-level trigger signal (SQLSTATE 45000)
            if (str_contains($e->getMessage(), 'already booked')) {
                $errors[] = 'This venue is already booked on the selected date. Please choose a different date or venue.';
            } else {
                $errors[] = 'Booking could not be saved: ' . $e->getMessage();
            }
        }

        if (empty($errors)) {
            logAudit($userId, 'BOOKING', "Created booking #{$ref} for package #{$pkgId}", 'bookings');
            setFlash('success', "Booking submitted successfully! Please wait for cashier approval.");
            redirect(APP_URL . '/customer/bookings.php');
        }
    }
}
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<script>
    window.packagePrices     = <?= json_encode($pkgPriceMap) ?>;
    window.packageDiscounts  = <?= json_encode($pkgDiscountMap) ?>;
    window.packageInclusions = <?= json_encode($pkgInclusionsMap) ?>;
    window.venueBookedDates  = <?= json_encode($venueBookedDatesMap) ?>;
    window.appUrl            = '<?= APP_URL ?>';
</script>

<style>
/* ════════════════════════════════════════════════════════════
   Unified Slot Availability Calendar Styles
   ════════════════════════════════════════════════════════════ */
.slot-calendar-container {
    background: #151528;
    border: 1.5px solid rgba(78,205,196,0.25);
    border-radius: var(--radius-md, 12px);
    padding: 16px;
    margin-top: 6px;
    margin-bottom: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.35);
}

.sc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.sc-month-title {
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    font-size: 1.12rem;
    color: var(--text-primary, #fff);
    letter-spacing: 0.02em;
}
.sc-nav-btn {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.12);
    color: var(--text-primary, #fff);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.85rem;
}
.sc-nav-btn:hover:not(:disabled) {
    background: rgba(78,205,196,0.2);
    border-color: rgba(78,205,196,0.5);
    color: var(--accent-teal, #4ecdc4);
    transform: scale(1.05);
}
.sc-nav-btn:disabled {
    opacity: 0.25;
    cursor: not-allowed;
}

.sc-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
    margin-bottom: 6px;
}
.sc-wd {
    text-align: center;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted, rgba(255,255,255,0.45));
    padding: 3px 0;
}

.sc-days-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 5px;
}
.sc-day {
    min-height: 52px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    border: 1.5px solid transparent;
    transition: all 0.18s ease;
    cursor: pointer;
    position: relative;
    padding: 4px 2px;
    user-select: none;
}
.sc-day .sc-day-num {
    font-size: 0.95rem;
    font-weight: 700;
    line-height: 1.1;
}
.sc-day .sc-slot-badge {
    font-size: 0.6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-top: 3px;
    line-height: 1;
    padding: 2px 4px;
    border-radius: 3px;
}

/* Empty filler day */
.sc-day.sc-empty {
    cursor: default;
    background: transparent !important;
    border: none !important;
}

/* Past day */
.sc-day.sc-past {
    cursor: not-allowed;
    color: rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.015);
}
.sc-day.sc-past .sc-slot-badge {
    color: rgba(255,255,255,0.2);
}

/* Available slot (Open) */
.sc-day.sc-avail {
    background: rgba(39,174,96,0.1);
    border-color: rgba(39,174,96,0.3);
    color: #2ecc71;
}
.sc-day.sc-avail .sc-slot-badge {
    background: rgba(39,174,96,0.22);
    color: #2ecc71;
}
.sc-day.sc-avail:hover {
    background: rgba(39,174,96,0.24);
    border-color: #2ecc71;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(39,174,96,0.3);
}

/* Booked slot (Unavailable) */
.sc-day.sc-booked {
    background: rgba(231,76,60,0.12);
    border-color: rgba(231,76,60,0.35);
    color: #e74c3c;
    cursor: not-allowed;
}
.sc-day.sc-booked .sc-day-num {
    text-decoration: line-through;
    opacity: 0.7;
}
.sc-day.sc-booked .sc-slot-badge {
    background: rgba(231,76,60,0.25);
    color: #ff7675;
}
.sc-day.sc-booked:hover {
    background: rgba(231,76,60,0.2);
}

/* Selected Day */
.sc-day.sc-selected {
    background: linear-gradient(135deg, #4ecdc4 0%, #26a69a 100%) !important;
    border-color: #fff !important;
    color: #0b0b1a !important;
    box-shadow: 0 4px 18px rgba(78,205,196,0.55);
    transform: scale(1.04);
}
.sc-day.sc-selected .sc-slot-badge {
    background: rgba(0,0,0,0.35) !important;
    color: #fff !important;
}

/* Today indicator */
.sc-day.sc-today:not(.sc-selected) {
    box-shadow: inset 0 0 0 1px rgba(78,205,196,0.6);
}

/* Legend */
.sc-legend {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 14px;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid rgba(255,255,255,0.08);
    font-size: 0.78rem;
    color: var(--text-muted, rgba(255,255,255,0.6));
}
.sc-legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
}
.sc-dot {
    width: 11px;
    height: 11px;
    border-radius: 3px;
    flex-shrink: 0;
}
.sc-dot.dot-avail    { background: rgba(39,174,96,0.3); border: 1.5px solid #2ecc71; }
.sc-dot.dot-booked   { background: rgba(231,76,60,0.3); border: 1.5px solid #e74c3c; }
.sc-dot.dot-selected { background: #4ecdc4; border: 1.5px solid #fff; }

/* Selected slot feedback box */
.selected-slot-box {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: var(--radius-sm, 8px);
    font-size: 0.88rem;
    font-weight: 600;
    transition: all 0.25s ease;
    margin-top: 6px;
}
.selected-slot-box.slot-empty {
    background: rgba(255,255,255,0.04);
    border: 1px dashed rgba(255,255,255,0.18);
    color: var(--text-muted, #888);
}
.selected-slot-box.slot-valid {
    background: rgba(39,174,96,0.12);
    border: 1.5px solid rgba(39,174,96,0.45);
    color: #2ecc71;
    box-shadow: 0 4px 16px rgba(39,174,96,0.2);
}
.selected-slot-box.slot-invalid {
    background: rgba(231,76,60,0.15);
    border: 1.5px solid rgba(231,76,60,0.45);
    color: #e74c3c;
    animation: signShake 0.4s ease;
}

@keyframes signShake {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-4px); }
    40%, 80% { transform: translateX(4px); }
}

/* ── Submit button disabled state ── */
#submit-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    filter: grayscale(0.4);
}
</style>

<div class="page-header">
    <div>
        <h1>Book a Package</h1>
        <p>Fill in the details below to reserve your craft experience</p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left"></i> Back to Packages
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <span class="alert-icon">✗</span>
        <div><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
    </div>
<?php endif; ?>

<?php if ($hasOngoingPayment): ?>
    <!-- Ongoing Payment Restriction Card -->
    <div class="card" style="background:linear-gradient(135deg,rgba(233,69,96,0.12),rgba(245,166,35,0.08));border:1.5px solid rgba(233,69,96,0.5);border-radius:20px;padding:36px 40px;margin-bottom:30px;text-align:center;box-shadow:0 12px 36px rgba(0,0,0,0.3);">
        <div style="width:68px;height:68px;border-radius:50%;background:rgba(233,69,96,0.2);border:2px solid #e94560;display:inline-flex;align-items:center;justify-content:center;color:#e94560;font-size:2rem;margin-bottom:18px;">
            <i class="fa-solid fa-lock"></i>
        </div>
        <h2 style="font-family:'Outfit',sans-serif;font-size:1.75rem;font-weight:800;color:#fff;margin-bottom:10px;">Package Booking Locked: Active Payment Required</h2>
        <p style="color:var(--text-secondary);font-size:1rem;max-width:640px;margin:0 auto 24px auto;line-height:1.6;">
            You currently have an active booking with an outstanding balance of <strong style="color:#e94560;font-size:1.2rem;"><?= formatCurrency($totalOngoingBalance) ?></strong>.
            Per CraftHub policy, all existing event bookings must be <strong>fully paid</strong> before you are permitted to purchase or reserve another package.
        </p>

        <!-- Unpaid Bookings Breakdown -->
        <div style="max-width:640px;margin:0 auto 28px auto;background:rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.09);border-radius:14px;padding:18px 24px;text-align:left;">
            <div style="font-size:0.82rem;font-weight:700;color:var(--accent-teal);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-file-invoice-dollar"></i> Unsettled Booking Balances:
            </div>
            <?php foreach ($ongoingBookings as $ob): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.07);flex-wrap:wrap;gap:8px;">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <strong style="color:var(--accent-teal);font-size:0.95rem;"><?= htmlspecialchars(getBookingRef($ob)) ?></strong>
                            <span style="font-size:0.85rem;color:var(--text-primary);font-weight:600;"><?= htmlspecialchars($ob['package_name']) ?></span>
                        </div>
                        <div style="font-size:0.78rem;color:var(--text-muted);margin-top:2px;">
                            <i class="fa-solid fa-calendar-day"></i> Event Date: <?= date('M d, Y', strtotime($ob['event_date'])) ?> &bull; Due Date: <strong style="color:<?= $ob['urgency'] === 'overdue' ? '#e94560' : '#f5a623' ?>;"><?= date('M d, Y', strtotime($ob['effective_due_date'])) ?></strong> (<?= htmlspecialchars($ob['urgency_label']) ?>)
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:0.75rem;color:var(--text-muted);">Remaining Balance</div>
                        <span style="color:#e94560;font-weight:800;font-size:1.05rem;"><?= formatCurrency($ob['calculated_balance']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
            <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-primary" style="background:#e94560;border-color:#e94560;padding:12px 28px;font-weight:700;">
                <i class="fa-solid fa-file-invoice"></i> View My Bookings &amp; Settle Balance
            </a>
            <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-secondary" style="padding:12px 28px;">
                <i class="fa-solid fa-arrow-left"></i> Return to Package Catalog
            </a>
        </div>
    </div>
<?php else: ?>

<div class="grid-2" style="align-items:start;">
    <!-- Booking Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-calendar-plus"></i> Booking Details</h2>
        </div>

        <form method="POST" action="" id="bookingForm">

            <div class="form-group">
                <label class="form-label" for="package_id">Select Package <span style="color:var(--accent-red);">*</span></label>
                <select id="package_id" name="package_id" class="form-control" required onchange="updateBookingSummary()">
                    <option value="">-- Choose a package --</option>
                    <?php foreach ($packages as $p):
                        $pDiscPct    = (float)($p['full_payment_discount_percent'] ?? 10);
                        $discHint    = $pDiscPct > 0 ? ' · Up to ' . rtrim(rtrim(number_format($pDiscPct, 2), '0'), '.') . '% off' : '';
                        $pAvail      = (int)($p['available_slots'] ?? max(0, ($p['max_slots'] ?? 5)));
                        $pIsFull     = $pAvail <= 0;
                        $slotHint    = $pIsFull ? ' [FULLY BOOKED]' : " [{$pAvail} slot" . ($pAvail === 1 ? '' : 's') . " left]";
                    ?>
                        <option value="<?= $p['id'] ?>"
                                <?= ($selectedPackage && $selectedPackage['id'] == $p['id']) || ($_POST['package_id'] ?? '') == $p['id'] ? 'selected' : '' ?>
                                <?= $pIsFull ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?> — <?= formatCurrency($p['price']) ?><?= $discHint ?><?= $slotHint ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="venue_id">
                    Select Venue <span style="color:var(--text-muted)">(optional)</span>
                </label>
                <select id="venue_id" name="venue_id" class="form-control" onchange="onVenueChange()">
                    <option value="">-- No specific venue --</option>
                    <?php foreach ($venues as $v): ?>
                        <option value="<?= $v['id'] ?>"
                            <?= (($_POST['venue_id'] ?? $preVenueId) == $v['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['name']) ?> (Capacity: <?= (int)($v['capacity'] ?? 0) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ── Single Integrated Slot Availability Calendar ── -->
            <div class="form-group">
                <label class="form-label" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <span><i class="fa-solid fa-calendar-days" style="color:var(--accent-teal);"></i> Event Date & Slot Availability <span style="color:var(--accent-red);">*</span></span>
                    <span id="sc-hint" style="font-size:0.78rem;color:var(--text-muted);font-weight:400;">Click an open slot to book</span>
                </label>

                <!-- Hidden input submitted with the form -->
                <input type="hidden" id="event_date" name="event_date"
                       value="<?= htmlspecialchars($_POST['event_date'] ?? $preDate) ?>" required>

                <div class="slot-calendar-container" id="slot-calendar">
                    <!-- Calendar Header -->
                    <div class="sc-header">
                        <button type="button" class="sc-nav-btn" id="sc-prev" onclick="scNavMonth(-1)" title="Previous month">
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        <div class="sc-month-title" id="sc-month-title">September 2026</div>
                        <button type="button" class="sc-nav-btn" id="sc-next" onclick="scNavMonth(1)" title="Next month">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>

                    <!-- Weekdays -->
                    <div class="sc-weekdays">
                        <div class="sc-wd">Sun</div>
                        <div class="sc-wd">Mon</div>
                        <div class="sc-wd">Tue</div>
                        <div class="sc-wd">Wed</div>
                        <div class="sc-wd">Thu</div>
                        <div class="sc-wd">Fri</div>
                        <div class="sc-wd">Sat</div>
                    </div>

                    <!-- Days Grid -->
                    <div class="sc-days-grid" id="sc-days-grid">
                        <!-- Dynamically populated by JS -->
                    </div>

                    <!-- Legend -->
                    <div class="sc-legend">
                        <div class="sc-legend-item"><span class="sc-dot dot-avail"></span> Available (Open Slot)</div>
                        <div class="sc-legend-item"><span class="sc-dot dot-booked"></span> Already Booked (Full)</div>
                        <div class="sc-legend-item"><span class="sc-dot dot-selected"></span> Selected Date</div>
                    </div>
                </div>

                <!-- Selected Slot Feedback / Status Box -->
                <div id="selected-slot-box" class="selected-slot-box slot-empty">
                    <i id="selected-slot-icon" class="fa-solid fa-calendar-check"></i>
                    <div id="selected-slot-text">Please click an available (green) date slot on the calendar above.</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="event_time">Event Time</label>
                    <input type="time" id="event_time" name="event_time" class="form-control"
                           value="<?= htmlspecialchars($_POST['event_time'] ?? '09:00') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="num_guests">Number of Guests <span style="color:var(--accent-red);">*</span></label>
                    <input type="number" id="num_guests" name="num_guests" class="form-control"
                           min="1" max="200" value="<?= (int)($_POST['num_guests'] ?? 1) ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Special Notes <span style="color:var(--text-muted)">(optional)</span></label>
                <textarea id="notes" name="notes" class="form-control" rows="3"
                          placeholder="Any special requests or details..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <!-- Payment Plan -->
            <div style="background:rgba(245,166,35,0.07);border:1px solid rgba(245,166,35,0.3);border-radius:12px;padding:14px 16px;margin-bottom:16px;">
                <div style="font-size:0.78rem;font-weight:800;color:var(--accent-gold);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;"><i class="fa-solid fa-tag"></i> Payment Plan</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <label id="plan-full-label" style="flex:1;min-width:140px;cursor:pointer;border:2px solid rgba(245,166,35,0.5);border-radius:10px;padding:12px 14px;transition:all 0.2s;background:rgba(245,166,35,0.1);">
                        <input type="radio" name="payment_plan" id="plan_full" value="full" checked style="margin-right:6px;">
                        <strong>Full Payment</strong>
                        <div id="full-badge" style="font-size:0.75rem;color:var(--accent-gold);margin-top:3px;">Save <span id="full-pct">10</span>%</div>
                    </label>
                    <label id="plan-dp-label" style="flex:1;min-width:140px;cursor:pointer;border:2px solid rgba(255,255,255,0.08);border-radius:10px;padding:12px 14px;transition:all 0.2s;">
                        <input type="radio" name="payment_plan" id="plan_dp" value="downpayment" style="margin-right:6px;">
                        <strong>Downpayment</strong>
                        <div id="dp-badge" style="font-size:0.75rem;color:var(--text-muted);margin-top:3px;">Save <span id="dp-pct">5</span>% &bull; Pay <span id="dp-percent-lbl">50</span>% now</div>
                    </label>
                </div>
            </div>

            <button type="submit" id="submit-btn" class="btn btn-primary btn-block btn-lg" disabled>
                <i class="fa-solid fa-calendar-check"></i> Submit Booking
            </button>
            <div id="submit-warning" style="display:block;text-align:center;margin-top:8px;font-size:0.8rem;color:var(--text-muted);">
                Please select an available date slot from the calendar.
            </div>
        </form>
    </div>

    <!-- Summary Panel -->
    <div>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-receipt"></i> Booking Summary</h2>
            </div>

            <!-- Pricing Breakdown -->
            <div style="padding:14px 16px 0;">
                <!-- Original Price row -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:0.88rem;color:var(--text-secondary);">
                    <span>Original Price</span>
                    <span id="sum_original">₱ 0.00</span>
                </div>
                <!-- Discount row -->
                <div id="sum_discount_row" style="display:none;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:0.88rem;color:#4ecdc4;">
                    <span><i class="fa-solid fa-tag" style="margin-right:4px;"></i>Discount (<span id="sum_discount_pct">0</span>%)</span>
                    <span style="color:#4ecdc4;">−₱ <span id="sum_discount_amt">0.00</span></span>
                </div>
                <hr style="border:none;border-top:1px solid var(--border-color);margin:8px 0;">
            </div>

            <div style="text-align:center;padding:10px 16px 4px;">
                <div style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:2px;">Total Amount</div>
                <div id="total_display" style="font-family:'Outfit',sans-serif;font-size:2.2rem;font-weight:800;color:var(--accent-gold);">₱ 0.00</div>
                <input type="hidden" id="total_amount" name="total_amount" value="0">
            </div>

            <!-- Due Today (downpayment) -->
            <div id="sum_due_today_block" style="display:none;margin:8px 16px;padding:10px 14px;background:rgba(245,166,35,0.1);border-radius:10px;border:1px solid rgba(245,166,35,0.3);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:0.82rem;color:var(--accent-gold);font-weight:700;"><i class="fa-solid fa-clock"></i> Due Today (Downpayment)</span>
                    <span id="sum_due_today" style="font-size:1rem;font-weight:800;color:var(--accent-gold);">₱ 0.00</span>
                </div>
                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;">Remaining balance due on or before the event.</div>
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
                <div><?= htmlspecialchars($user['phone'] ?? $user['contact_no'] ?? 'No phone on file') ?></div>
            </div>
        </div>

        <!-- Slot Availability Guidelines Card -->
        <div class="card mt-2" style="background:rgba(78,205,196,0.05);border:1px solid rgba(78,205,196,0.2);">
            <div style="padding:14px;">
                <div style="font-weight:700;margin-bottom:8px;font-size:0.9rem;color:var(--accent-teal);">
                    <i class="fa-solid fa-circle-check"></i> Live Slot Availability
                </div>
                <div style="font-size:0.82rem;color:var(--text-secondary);line-height:1.7;">
                    • 🟢 <strong>Green Slots</strong> are open and ready to book.<br>
                    • 🔴 <strong>Red Slots</strong> are already reserved for that venue.<br>
                    • Click any green slot on the calendar to select your event date.
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$hasOngoingPayment): ?>
<script>
// ── State ──────────────────────────────────────────────────
var MONTHS = ['January','February','March','April','May','June',
              'July','August','September','October','November','December'];

var scToday = new Date();
scToday.setHours(0,0,0,0);
var scMinDate = new Date(scToday);
scMinDate.setDate(scToday.getDate() + 1); // tomorrow min

var scSelectedDate = document.getElementById('event_date').value || '';
var scBase = scSelectedDate ? new Date(scSelectedDate + 'T00:00:00') : new Date(scMinDate);
if (isNaN(scBase.getTime())) scBase = new Date(scMinDate);

var scYear  = scBase.getFullYear();
var scMonth = scBase.getMonth(); // 0-based
var currentAvailability = false;
var availabilityCheckTimer = null;

// ── Pad number helper ──────────────────────────────────────
function scPad(n){ return String(n).padStart(2, '0'); }
function scFormatDateStr(y, m, d){ return y + '-' + scPad(m + 1) + '-' + scPad(d); }

// ── Check if a date is booked for the active venue ────────
function isVenueDateBooked(dateStr) {
    var venueId = document.getElementById('venue_id').value;
    if (!venueId) return false;
    var booked = (window.venueBookedDates && window.venueBookedDates[venueId]) ? window.venueBookedDates[venueId] : [];
    return booked.indexOf(dateStr) !== -1;
}

// ── Render the Single Slot Availability Calendar ──────────
function scRender() {
    var titleEl = document.getElementById('sc-month-title');
    var gridEl  = document.getElementById('sc-days-grid');
    var prevBtn = document.getElementById('sc-prev');
    if (!titleEl || !gridEl) return;

    titleEl.textContent = MONTHS[scMonth] + ' ' + scYear;

    // Disable prev button if viewing the current minimum month
    var minY = scMinDate.getFullYear(), minM = scMinDate.getMonth();
    prevBtn.disabled = (scYear < minY) || (scYear === minY && scMonth <= minM);

    var firstDow = new Date(scYear, scMonth, 1).getDay(); // 0 = Sun
    var daysInMonth = new Date(scYear, scMonth + 1, 0).getDate();

    var html = '';
    // Empty filler cells before 1st of month
    for (var e = 0; e < firstDow; e++) {
        html += '<div class="sc-day sc-empty"></div>';
    }

    for (var d = 1; d <= daysInMonth; d++) {
        var dateStr = scFormatDateStr(scYear, scMonth, d);
        var dt      = new Date(scYear, scMonth, d);
        dt.setHours(0,0,0,0);

        var isPast     = dt < scMinDate;
        var isBooked   = !isPast && isVenueDateBooked(dateStr);
        var isSelected = (dateStr === scSelectedDate);
        var isToday    = (dt.getTime() === scToday.getTime());

        var cls = 'sc-day';
        var badgeText = '';
        var clickAttr = '';
        var titleAttr = '';

        if (isPast) {
            cls += ' sc-past';
            badgeText = 'Past';
            titleAttr = 'Past date (unavailable)';
        } else if (isSelected) {
            cls += ' sc-selected';
            badgeText = isBooked ? 'Booked' : 'Selected';
            titleAttr = 'Selected date';
            clickAttr = 'onclick="scSelectSlot(\'' + dateStr + '\')"';
        } else if (isBooked) {
            cls += ' sc-booked';
            badgeText = 'Booked';
            titleAttr = 'This slot is already booked for this venue';
            clickAttr = 'onclick="scBookedSlotClick(\'' + dateStr + '\')"';
        } else {
            cls += ' sc-avail';
            badgeText = 'Open';
            titleAttr = 'Slot is available! Click to choose this date';
            clickAttr = 'onclick="scSelectSlot(\'' + dateStr + '\')"';
        }

        if (isToday && !isSelected) cls += ' sc-today';

        html += '<div class="' + cls + '" ' + clickAttr + ' title="' + titleAttr + '">';
        html += '  <span class="sc-day-num">' + d + '</span>';
        html += '  <span class="sc-slot-badge">' + badgeText + '</span>';
        html += '</div>';
    }

    gridEl.innerHTML = html;
}

// ── Navigate Months ────────────────────────────────────────
function scNavMonth(dir) {
    scMonth += dir;
    if (scMonth < 0)  { scMonth = 11; scYear--; }
    if (scMonth > 11) { scMonth = 0;  scYear++; }
    scRender();
}

// ── Select Available Slot ──────────────────────────────────
function scSelectSlot(dateStr) {
    scSelectedDate = dateStr;
    document.getElementById('event_date').value = dateStr;

    // Visual feedback
    var box  = document.getElementById('selected-slot-box');
    var icon = document.getElementById('selected-slot-icon');
    var text = document.getElementById('selected-slot-text');

    box.className = 'selected-slot-box slot-valid';
    icon.className = 'fa-solid fa-circle-check';
    text.innerHTML = '<strong>Slot Selected:</strong> ' + formatDateDisplay(dateStr) + ' is available for booking!';

    scRender();
    checkAvailability();
}

// ── Clicked Booked Slot Warning ────────────────────────────
function scBookedSlotClick(dateStr) {
    var box  = document.getElementById('selected-slot-box');
    var icon = document.getElementById('selected-slot-icon');
    var text = document.getElementById('selected-slot-text');

    box.className = 'selected-slot-box slot-invalid';
    icon.className = 'fa-solid fa-ban';
    text.innerHTML = '<strong>Already Booked:</strong> ' + formatDateDisplay(dateStr) + ' is already taken for this venue. Please pick an open (green) slot.';

    // Shake animation
    box.style.animation = 'none';
    box.offsetHeight; // trigger reflow
    box.style.animation = 'signShake 0.4s ease';

    currentAvailability = false;
    updateSubmitButton();
}

// ── Package Summary ────────────────────────────────────────
function fmt(n) {
    return '₱ ' + parseFloat(n || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function fmtNum(n) {
    return parseFloat(n || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function updateBookingSummary() {
    var pkgId        = document.getElementById('package_id').value;
    var planFull     = document.getElementById('plan_full');
    var plan         = (planFull && planFull.checked) ? 'full' : 'downpayment';

    var basePrice    = (window.packagePrices && window.packagePrices[pkgId]) ? parseFloat(window.packagePrices[pkgId]) : 0;
    var discounts    = (window.packageDiscounts && window.packageDiscounts[pkgId]) ? window.packageDiscounts[pkgId] : {full_payment_discount_percent:10, downpayment_discount_percent:5, downpayment_percent:50};

    var fullPct      = parseFloat(discounts.full_payment_discount_percent || 10);
    var dpPct        = parseFloat(discounts.downpayment_discount_percent  || 5);
    var dpReqPct     = parseFloat(discounts.downpayment_percent           || 50);

    // Update plan badge labels
    var fpctEl = document.getElementById('full-pct');
    var dpctEl = document.getElementById('dp-pct');
    var dpplEl = document.getElementById('dp-percent-lbl');
    if (fpctEl) fpctEl.textContent = fullPct;
    if (dpctEl) dpctEl.textContent = dpPct;
    if (dpplEl) dpplEl.textContent = dpReqPct;

    // Update plan label styles
    var planFullLabel = document.getElementById('plan-full-label');
    var planDpLabel   = document.getElementById('plan-dp-label');
    if (plan === 'full') {
        if (planFullLabel) { planFullLabel.style.border='2px solid rgba(245,166,35,0.8)'; planFullLabel.style.background='rgba(245,166,35,0.12)'; }
        if (planDpLabel)   { planDpLabel.style.border='2px solid rgba(255,255,255,0.08)'; planDpLabel.style.background=''; }
    } else {
        if (planDpLabel)   { planDpLabel.style.border='2px solid rgba(245,166,35,0.8)'; planDpLabel.style.background='rgba(245,166,35,0.12)'; }
        if (planFullLabel) { planFullLabel.style.border='2px solid rgba(255,255,255,0.08)'; planFullLabel.style.background=''; }
    }

    var discPct      = (plan === 'full') ? fullPct : dpPct;
    var discAmt      = basePrice * discPct / 100;
    var discTotal    = basePrice - discAmt;
    var dueToday     = (plan === 'downpayment') ? (discTotal * dpReqPct / 100) : discTotal;

    // Update DOM
    var sumOrigEl  = document.getElementById('sum_original');
    var sumDrRow   = document.getElementById('sum_discount_row');
    var sumDrPct   = document.getElementById('sum_discount_pct');
    var sumDrAmt   = document.getElementById('sum_discount_amt');
    var totalDisp  = document.getElementById('total_display');
    var totalHid   = document.getElementById('total_amount');
    var dueTodayBl = document.getElementById('sum_due_today_block');
    var dueTodayEl = document.getElementById('sum_due_today');

    if (sumOrigEl) sumOrigEl.textContent = basePrice > 0 ? fmt(basePrice) : '₱ 0.00';

    if (basePrice > 0 && discPct > 0) {
        if (sumDrRow) { sumDrRow.style.display = 'flex'; }
        if (sumDrPct) sumDrPct.textContent = discPct;
        if (sumDrAmt) sumDrAmt.textContent  = fmtNum(discAmt);
    } else {
        if (sumDrRow) sumDrRow.style.display = 'none';
    }

    if (totalDisp) totalDisp.textContent = basePrice > 0 ? fmt(discTotal) : '₱ 0.00';
    if (totalHid)  totalHid.value = discTotal.toFixed(2);

    if (plan === 'downpayment' && basePrice > 0) {
        if (dueTodayBl) dueTodayBl.style.display = 'block';
        if (dueTodayEl) dueTodayEl.textContent = fmt(dueToday);
    } else {
        if (dueTodayBl) dueTodayBl.style.display = 'none';
    }

    // Inclusions
    var incBox  = document.getElementById('inclusions_box');
    var incList = document.getElementById('inclusions_list');
    if (incBox && incList) {
        if (pkgId && window.packageInclusions && window.packageInclusions[pkgId] && window.packageInclusions[pkgId].length > 0) {
            var items = window.packageInclusions[pkgId];
            var html  = '';
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
}

// ── Venue Change: refresh calendar slot indicators ────────
function onVenueChange() {
    var venueId   = document.getElementById('venue_id').value;
    var hintEl    = document.getElementById('sc-hint');

    if (hintEl) {
        if (venueId) {
            hintEl.textContent = 'Showing live slots for selected venue';
            hintEl.style.color = 'var(--accent-teal)';
        } else {
            hintEl.textContent = 'Click an open slot to book';
            hintEl.style.color = 'var(--text-muted)';
        }
    }

    // Re-render calendar so booked days for this venue turn red
    scRender();

    // Re-check current selected slot if any
    if (scSelectedDate) {
        if (isVenueDateBooked(scSelectedDate)) {
            scBookedSlotClick(scSelectedDate);
        } else {
            checkAvailability();
        }
    }
}

// ── Debounced Server Availability Check ───────────────────
function checkAvailability() {
    var venueId   = document.getElementById('venue_id').value;
    var eventDate = scSelectedDate;

    if (!eventDate) {
        currentAvailability = false;
        updateSubmitButton();
        return;
    }

    // Client-side quick check
    if (venueId && isVenueDateBooked(eventDate)) {
        scBookedSlotClick(eventDate);
        return;
    }

    if (!venueId) {
        currentAvailability = true;
        updateSubmitButton();
        return;
    }

    clearTimeout(availabilityCheckTimer);
    availabilityCheckTimer = setTimeout(function() {
        var url = window.appUrl + '/customer/check_availability.php?venue_id='
                  + encodeURIComponent(venueId) + '&event_date=' + encodeURIComponent(eventDate);

        fetch(url, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(data){
                var box  = document.getElementById('selected-slot-box');
                var icon = document.getElementById('selected-slot-icon');
                var text = document.getElementById('selected-slot-text');

                if (data.available) {
                    currentAvailability = true;
                    box.className = 'selected-slot-box slot-valid';
                    icon.className = 'fa-solid fa-circle-check';
                    text.innerHTML = '<strong>Slot Available:</strong> ' + formatDateDisplay(eventDate) + ' is confirmed open!';
                } else {
                    currentAvailability = false;
                    box.className = 'selected-slot-box slot-invalid';
                    icon.className = 'fa-solid fa-ban';
                    text.innerHTML = '<strong>Unavailable:</strong> ' + (data.message || 'Already booked on this date.');
                    // Update local booked cache & re-render
                    if (window.venueBookedDates && window.venueBookedDates[venueId] && window.venueBookedDates[venueId].indexOf(eventDate) === -1) {
                        window.venueBookedDates[venueId].push(eventDate);
                        scRender();
                    }
                }
                updateSubmitButton();
            })
            .catch(function(){
                // On network timeout, allow submit (server PHP will validate on POST)
                currentAvailability = true;
                updateSubmitButton();
            });
    }, 350);
}

function updateSubmitButton() {
    var btn     = document.getElementById('submit-btn');
    var warning = document.getElementById('submit-warning');

    if (!scSelectedDate) {
        btn.disabled = true;
        warning.style.display = 'block';
        warning.textContent = 'Please select an available date slot from the calendar.';
        warning.style.color = 'var(--text-muted)';
    } else if (!currentAvailability) {
        btn.disabled = true;
        warning.style.display = 'block';
        warning.textContent = 'This venue and date are unavailable. Please choose another slot.';
        warning.style.color = '#e74c3c';
    } else {
        btn.disabled = false;
        warning.style.display = 'none';
    }
}

function formatDateDisplay(dateStr) {
    if (!dateStr) return '';
    var p = dateStr.split('-');
    if (p.length < 3) return dateStr;
    var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return m[parseInt(p[1],10)-1] + ' ' + parseInt(p[2],10) + ', ' + p[0];
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
               .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

// ── Prevent submit if unavailable ─────────────────────────
document.getElementById('bookingForm').addEventListener('submit', function(e){
    if (!scSelectedDate || !currentAvailability) {
        e.preventDefault();
        var box = document.getElementById('selected-slot-box');
        if (box) box.scrollIntoView({ behavior:'smooth', block:'center' });
    }
});

// ── Initialize on DOM ready ───────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
    updateBookingSummary();
    scRender();

    if (scSelectedDate) {
        scSelectSlot(scSelectedDate);
    } else {
        updateSubmitButton();
    }

    var venueId = document.getElementById('venue_id').value;
    if (venueId) onVenueChange();

    // Payment plan radio listeners
    ['plan_full','plan_dp'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', updateBookingSummary);
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

