<?php
$pageTitle = 'Process Payment';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin', 'staff', 'cashier']);

$db          = getDB();
$staff       = getCurrentUser();
$bookingId   = (int)($_GET['id'] ?? 0);
$isRoleAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$returnUrl   = $isRoleAdmin ? APP_URL . '/admin/bills.php' : APP_URL . '/staff/payments.php';

if (!$bookingId) {
    setFlash('error', 'Invalid booking ID.');
    redirect($returnUrl);
}

$booking = getBookingById($bookingId);

if (!$booking) {
    setFlash('error', 'Booking not found.');
    redirect($returnUrl);
}

$amountPaid  = round((float)($booking['amount_paid'] ?? 0), 2);
$totalAmount = round((float)($booking['total_amount'] ?? 0), 2);
$balance     = round(max(0.0, $totalAmount - $amountPaid), 2);

if (strtolower($booking['status'] ?? '') === 'cancelled') {
    setFlash('error', 'Cannot collect payment: This booking has been cancelled.');
    redirect($returnUrl);
}

if (strtolower($booking['status'] ?? '') === 'paid' || ($balance <= 0 && $totalAmount > 0)) {
    setFlash('info', 'This booking is already fully paid.');
    redirect($returnUrl);
}

$errors = [];

// Get previous payments for this booking
$prevStmt = $db->prepare("SELECT py.*, u.name AS cashier_name FROM payments py JOIN users u ON py.cashier_id = u.user_id WHERE py.booking_id = ? ORDER BY py.payment_date DESC");
$prevStmt->execute([$bookingId]);
$prevPayments = $prevStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Real-time verification of booking status before collecting payment
    $freshCheck = $db->prepare("SELECT status FROM bookings WHERE booking_id = ?");
    $freshCheck->execute([$bookingId]);
    $freshStatus = $freshCheck->fetchColumn();

    if (strtolower($freshStatus) === 'cancelled') {
        $errors[] = 'Cannot collect payment: This booking has been cancelled.';
    }

    $amount    = (float)($_POST['amount_paid']    ?? 0);
    $payType   = sanitize($_POST['payment_type']  ?? ($amount >= $balance ? 'full' : 'downpayment'));

    if ($amount <= 0)      $errors[] = 'Payment amount must be greater than 0.';
    if ($amount > $balance) $errors[] = 'Amount cannot exceed the remaining balance of ' . formatCurrency($balance) . '.';

    $method    = sanitize($_POST['payment_method']?? 'cash');
    $reference = sanitize($_POST['reference_no']  ?? '');
    $notes     = sanitize($_POST['notes']         ?? '');

    if (empty($errors)) {
        $paymentSuccess = false;
        $newPayId       = 0;
        $staffId        = (int)($staff['user_id'] ?? $staff['id'] ?? 0);
        $orNumber       = 'OR-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);

        try {
            $db->beginTransaction();

            try {
                $ins = $db->prepare("INSERT INTO payments (booking_id, cashier_id, amount_paid, payment_type, payment_method, reference_no, or_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$bookingId, $staffId, $amount, $payType, $method, $reference ?: null, $orNumber, $notes ?: null]);
            } catch (PDOException $e) {
                $ins = $db->prepare("INSERT INTO payments (booking_id, cashier_id, amount_paid, payment_type, or_number) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$bookingId, $staffId, $amount, $payType, $orNumber]);
            }

            $newPayId = (int)$db->lastInsertId();

            // Update booking status & amount_paid
            try {
                $upd = $db->prepare("UPDATE bookings SET amount_paid = COALESCE(amount_paid, 0) + ? WHERE booking_id = ?");
                $upd->execute([$amount, $bookingId]);
            } catch (PDOException $e) {
                // Ignore if amount_paid column doesn't exist
            }

            if (strtolower($freshStatus ?? '') === 'pending') {
                ensureApprovalColumns($db);
                $db->prepare("UPDATE bookings SET status = 'Confirmed', approved_at = NOW(), approved_by = ? WHERE booking_id = ?")
                   ->execute([$staffId, $bookingId]);
            }

            updateBookingPaymentStatus($bookingId);

            $db->commit();
            $paymentSuccess = true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Payment processing error: ' . $e->getMessage());
            $errors[] = 'Payment processing failed. Please try again.';
        }

        if ($paymentSuccess && $newPayId > 0) {
            // Send Email Receipt safely
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                sendPaymentReceiptEmail($newPayId);
            } catch (Throwable $mailEx) {
                error_log('Receipt email failed: ' . $mailEx->getMessage());
            }

            logAudit($staffId, 'PAYMENT', "Processed payment of " . formatCurrency($amount) . " for booking #{$bookingId}", 'payments');
            setFlash('success', 'Payment of ' . formatCurrency($amount) . ' processed successfully! <a href="' . APP_URL . '/receipt.php?id=' . $newPayId . '" target="_blank" style="color:#fff;text-decoration:underline;margin-left:8px;font-weight:700;"><i class="fa-solid fa-receipt"></i> View Official Receipt (' . $orNumber . ')</a>');
            redirect($returnUrl);
        }
    }
}
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Process Payment</h1>
        <p>Booking: <strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($booking)) ?></strong></p>
    </div>
    <a href="<?= $returnUrl ?>" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <span class="alert-icon">✗</span>
        <div><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($booking['approved_at'])): ?>
    <div style="background:rgba(39,174,96,0.1);border:1px solid rgba(39,174,96,0.3);color:#27ae60;padding:12px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:10px;font-size:0.9rem;">
        <i class="fa-solid fa-circle-check" style="font-size:1.2rem;"></i>
        <div>
            Booking Approved on <strong><?= date('M d, Y g:i A', strtotime($booking['approved_at'])) ?></strong>. Ready for walk-in cash payment.
        </div>
    </div>
<?php endif; ?>

<div class="grid-2" style="align-items:start;">

    <!-- Payment Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-money-bill-wave"></i> Payment Form</h2>
        </div>

        <form method="POST" action="" data-validate>
            <div class="form-group">
                <label class="form-label">Balance Due</label>
                <div style="font-family:'Outfit',sans-serif;font-size:2rem;font-weight:800;color:var(--accent-red);padding:12px 0;">
                    <?= formatCurrency($balance) ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="amount_paid">Amount to Collect <span style="color:var(--accent-red);">*</span></label>
                <input type="number" id="amount_paid" name="amount_paid" class="form-control"
                       step="0.01" min="0.01" max="<?= $balance ?>"
                       value="<?= number_format($balance, 2, '.', '') ?>"
                       placeholder="Enter amount" required>
                <div class="form-hint">Max: <?= formatCurrency($balance) ?></div>
            </div>

            <div class="form-group">
                <label class="form-label" for="payment_method">Payment Method <span style="color:var(--accent-red);">*</span></label>
                <select id="payment_method" name="payment_method" class="form-control" style="background:rgba(39,174,96,0.1);border-color:rgba(39,174,96,0.4);color:#27ae60;font-weight:700;" required>
                    <option value="cash" selected>💵 Cash (Walk-in Only)</option>
                </select>
                <div class="form-hint" style="color:var(--text-muted);font-size:0.78rem;margin-top:5px;">
                    <i class="fa-solid fa-person-walking"></i> Walk-in clients only. Payments must be collected in cash at the counter.
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="reference_no">Cash Receipt / Counter Slip No. <span style="color:var(--text-muted)">(optional)</span></label>
                <input type="text" id="reference_no" name="reference_no" class="form-control"
                       placeholder="e.g. Counter slip #, petty cash voucher...">
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes <span style="color:var(--text-muted)">(optional)</span></label>
                <textarea id="notes" name="notes" class="form-control" rows="2"></textarea>
            </div>

            <button type="submit" class="btn btn-success btn-block btn-lg">
                <i class="fa-solid fa-check"></i> Confirm Payment
            </button>
        </form>
    </div>

    <!-- Booking Details -->
    <div>
        <div class="card" style="margin-bottom:20px;">
            <div class="card-header">
                <h2 class="card-title"><i class="fa-solid fa-file-invoice"></i> Booking Details</h2>
            </div>
            <div style="display:grid;gap:10px;font-size:0.9rem;">
                <div class="flex-between"><span style="color:var(--text-secondary);">Customer</span> <strong><?= htmlspecialchars($booking['customer_name']) ?></strong></div>
                <div class="flex-between"><span style="color:var(--text-secondary);">Email</span> <span><?= htmlspecialchars($booking['customer_email']) ?></span></div>
                <div class="flex-between"><span style="color:var(--text-secondary);">Phone</span> <span><?= htmlspecialchars($booking['customer_phone'] ?? '—') ?></span></div>
                <hr style="border-color:var(--border-color);">
                <div class="flex-between"><span style="color:var(--text-secondary);">Package</span> <strong><?= htmlspecialchars($booking['package_name']) ?></strong></div>
                <div class="flex-between"><span style="color:var(--text-secondary);">Venue</span> <span><?= htmlspecialchars($booking['venue_name'] ?? '—') ?></span></div>
                <div class="flex-between"><span style="color:var(--text-secondary);">Event Date</span> <span><?= formatDate($booking['event_date']) ?></span></div>
                <div class="flex-between"><span style="color:var(--text-secondary);">Total Guests</span> <strong><?= (int)($booking['guest_count'] ?? 1) ?> guests</strong></div>
                <?php if ((float)($booking['excess_guest_fee'] ?? 0) > 0): ?>
                    <div class="flex-between" style="background:rgba(245,166,35,0.08);padding:6px 10px;border-radius:8px;border:1px solid rgba(245,166,35,0.25);">
                        <span style="color:#f5a623;font-size:0.82rem;">
                            <i class="fa-solid fa-user-plus"></i> Excess Guests Add-On (+<?= (int)$booking['excess_guests'] ?> @ <?= formatCurrency($booking['per_guest_fee'] ?? 75) ?>)
                        </span>
                        <strong style="color:#f5a623;">+<?= formatCurrency($booking['excess_guest_fee']) ?></strong>
                    </div>
                <?php endif; ?>
                <hr style="border-color:var(--border-color);">
                <div class="flex-between"><span style="color:var(--text-secondary);">Total Amount</span> <strong style="color:var(--accent-gold);"><?= formatCurrency($booking['total_amount']) ?></strong></div>
                <div class="flex-between"><span style="color:var(--text-secondary);">Amount Paid</span> <strong style="color:#27ae60;"><?= formatCurrency($booking['amount_paid']) ?></strong></div>
                <div class="flex-between" style="border-top:1px solid var(--border-color);padding-top:10px;"><span style="color:var(--text-secondary);">Balance</span> <strong style="color:var(--accent-red);font-size:1.1rem;"><?= formatCurrency($balance) ?></strong></div>
                <hr style="border-color:var(--border-color);">
                <?php
                $depAmt = (float)($booking['security_deposit'] ?? 0);
                $depSt  = $booking['deposit_status'] ?? 'held';
                $depLabels = ['held'=>'Held (Refundable)','returned'=>'Returned (No Damage)','forfeited'=>'Forfeited (Damage)'];
                $depColors = ['held'=>'#f5a623','returned'=>'#27ae60','forfeited'=>'#e94560'];
                ?>
                <div class="flex-between">
                    <span style="color:var(--text-secondary);"><i class="fa-solid fa-shield-halved" style="color:#a78bfa;"></i> Security Deposit</span>
                    <strong style="color:#a78bfa;"><?= formatCurrency($depAmt) ?></strong>
                </div>
                <div class="flex-between">
                    <span style="color:var(--text-secondary);">Deposit Status</span>
                    <span style="font-size:0.78rem;font-weight:700;color:<?= $depColors[$depSt] ?? '#aaa' ?>;background:<?= ($depColors[$depSt] ?? '#aaa') ?>18;padding:2px 8px;border-radius:20px;border:1px solid <?= ($depColors[$depSt] ?? '#aaa') ?>44;">
                        <?= $depLabels[$depSt] ?? ucfirst($depSt) ?>
                    </span>
                </div>
                <div style="font-size:0.73rem;color:var(--text-muted);margin-top:2px;line-height:1.4;">
                    <i class="fa-solid fa-circle-info" style="color:#a78bfa;"></i> The security deposit is refundable back to the customer after the event if no damage is incurred.
                </div>
            </div>
        </div>

        <?php if (!empty($prevPayments)): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title" style="font-size:0.95rem;"><i class="fa-solid fa-history"></i> Previous Payments</h2>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($prevPayments as $py): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:rgba(39,174,96,0.05);border-radius:var(--radius-sm);border:1px solid rgba(39,174,96,0.15);">
                    <div>
                        <div style="font-size:0.85rem;font-weight:600;"><?= formatCurrency($py['amount_paid']) ?></div>
                        <div style="font-size:0.75rem;color:var(--text-secondary);"><?= ucwords(str_replace('_',' ', $py['payment_method'] ?? 'cash')) ?> • <?= formatDate($py['payment_date']) ?></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:0.75rem;color:var(--text-muted);">By <?= htmlspecialchars($py['cashier_name']) ?></span>
                        <a href="<?= APP_URL ?>/receipt.php?id=<?= $py['payment_id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="font-size:0.72rem;padding:3px 8px;" title="Print Receipt">
                            <i class="fa-solid fa-receipt"></i> Receipt
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
