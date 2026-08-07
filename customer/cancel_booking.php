<?php
$pageTitle = 'Cancel Booking';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);
requireApproved();

$user      = getCurrentUser();
$db        = getDB();
$bookingId = (int)($_GET['id'] ?? 0);

if (!$bookingId) {
    setFlash('error', 'Invalid booking.');
    redirect(APP_URL . '/customer/bookings.php');
}

$stmt = $db->prepare("SELECT b.*, b.booking_id AS id, p.package_name AS package_name FROM bookings b JOIN packages p ON b.package_id = p.package_id WHERE b.booking_id = ? AND b.customer_id = ?");
$userId = $user['user_id'] ?? $user['id'];
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found.');
    redirect(APP_URL . '/customer/bookings.php');
}

if (strtolower($booking['status']) !== 'pending') {
    setFlash('error', 'Only pending bookings can be cancelled.');
    redirect(APP_URL . '/customer/bookings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = sanitize($_POST['reason'] ?? '');
    $upd    = $db->prepare("UPDATE bookings SET status = 'Cancelled' WHERE booking_id = ?");
    $upd->execute([$bookingId]);
    logAudit($userId, 'CANCEL_BOOKING', "Cancelled booking #{$bookingId}", 'bookings');
    setFlash('success', 'Booking #' . $bookingId . ' has been cancelled.');
    redirect(APP_URL . '/customer/bookings.php');
}
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Cancel Booking</h1>
        <p>Please confirm your cancellation request</p>
    </div>
    <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>
</div>

<div class="grid-2" style="align-items:start;max-width:800px;">
    <div class="card" style="border-color:rgba(233,69,96,0.3);">
        <div class="card-header">
            <h2 class="card-title" style="color:var(--accent-red);">
                <i class="fa-solid fa-triangle-exclamation"></i> Confirm Cancellation
            </h2>
        </div>

        <div style="margin-bottom:24px;padding:16px;background:rgba(233,69,96,0.05);border-radius:var(--radius-md);border:1px solid rgba(233,69,96,0.15);">
            <p style="font-size:0.9rem;color:var(--text-secondary);line-height:1.6;">
                You are about to cancel the following booking. This action cannot be undone.
            </p>
        </div>

        <div style="display:grid;gap:12px;margin-bottom:24px;font-size:0.9rem;">
            <div class="flex-between" style="padding:10px 0;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-secondary);">Reference</span>
                <strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($booking)) ?></strong>
            </div>
            <div class="flex-between" style="padding:10px 0;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-secondary);">Package</span>
                <strong><?= htmlspecialchars($booking['package_name']) ?></strong>
            </div>
            <div class="flex-between" style="padding:10px 0;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-secondary);">Event Date</span>
                <strong><?= formatDate($booking['event_date']) ?></strong>
            </div>
            <div class="flex-between" style="padding:10px 0;">
                <span style="color:var(--text-secondary);">Total Amount</span>
                <strong style="color:var(--accent-gold);"><?= formatCurrency($booking['total_amount']) ?></strong>
            </div>
        </div>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label" for="reason">Cancellation Reason <span style="color:var(--text-muted)">(optional)</span></label>
                <textarea id="reason" name="reason" class="form-control" rows="3"
                          placeholder="Why are you cancelling this booking?"></textarea>
            </div>

            <div style="display:flex;gap:12px;">
                <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-secondary" style="flex:1;justify-content:center;">
                    <i class="fa-solid fa-arrow-left"></i> Keep Booking
                </a>
                <button type="submit" class="btn btn-danger" style="flex:1;">
                    <i class="fa-solid fa-xmark"></i> Yes, Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
