<?php
$pageTitle = 'Submit Online Payment';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);

$db     = getDB();
$user   = getCurrentUser();
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

// Booking ID from GET
$bookingId = (int)($_GET['id'] ?? 0);
if (!$bookingId) {
    setFlash('error', 'Invalid booking reference.');
    redirect(APP_URL . '/customer/bookings.php');
}

// Fetch booking — must belong to this customer
$stmt = $db->prepare("
    SELECT b.*, b.booking_id AS id, p.package_name,
           (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS amount_paid_calc
    FROM bookings b
    JOIN packages p ON b.package_id = p.package_id
    WHERE b.booking_id = ? AND b.customer_id = ?
");
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found.');
    redirect(APP_URL . '/customer/bookings.php');
}

// Only Confirmed bookings can have payment submitted
if (!in_array($booking['status'], ['Confirmed', 'Paid'])) {
    setFlash('error', 'This booking is not yet approved for payment. Please wait for staff confirmation.');
    redirect(APP_URL . '/customer/bookings.php');
}

$total   = round((float)$booking['total_amount'], 2);
$paid    = round((float)$booking['amount_paid_calc'], 2);
$balance = max(0.0, $total - $paid);

if ($balance < 0.01) {
    setFlash('info', 'This booking is already fully paid.');
    redirect(APP_URL . '/customer/bookings.php');
}

// Check for pending submission
$pendingSub = $db->prepare("
    SELECT * FROM payment_submissions
    WHERE booking_id = ? AND customer_id = ? AND status = 'pending'
    ORDER BY created_at DESC LIMIT 1
");
$pendingSub->execute([$bookingId, $userId]);
$existingPending = $pendingSub->fetch();

$errors = [];

// POST: Process submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount        = round((float)($_POST['amount'] ?? 0), 2);
    $paymentMethod = trim($_POST['payment_method'] ?? 'gcash');
    $referenceNo   = trim($_POST['reference_no'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    // Validations
    if ($amount < 1) {
        $errors[] = 'Please enter a valid payment amount.';
    } elseif ($amount > $balance + 0.01) {
        $errors[] = "Amount (₱" . number_format($amount, 2) . ") exceeds outstanding balance (₱" . number_format($balance, 2) . ").";
    }
    if (empty($referenceNo)) {
        $errors[] = 'Reference number / transaction ID is required.';
    }

    // File upload validation
    $proofPath = '';
    if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Payment proof/screenshot is required.';
    } else {
        $file     = $_FILES['proof_image'];
        $maxSize  = 5 * 1024 * 1024; // 5MB
        $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mime     = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($file['size'] > $maxSize) {
            $errors[] = 'File must be under 5MB.';
        } elseif (!in_array($mime, $allowed)) {
            $errors[] = 'Only JPG, PNG, GIF, WebP, and PDF files are accepted.';
        } else {
            $uploadDir = __DIR__ . '/../uploads/payment_proofs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $ext       = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename  = 'proof_' . $bookingId . '_' . $userId . '_' . time() . '.' . $ext;
            $destPath  = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                $errors[] = 'Failed to upload file. Please try again.';
            } else {
                $proofPath = 'uploads/payment_proofs/' . $filename;
            }
        }
    }

    if (empty($errors)) {
        try {
            $ins = $db->prepare("
                INSERT INTO payment_submissions
                    (booking_id, customer_id, amount, payment_method, reference_no, proof_image, notes, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $ins->execute([$bookingId, $userId, $amount, $paymentMethod, $referenceNo, $proofPath, $notes]);
            $subId = (int)$db->lastInsertId();

            // Notify cashier/staff
            notifyAdminsAndStaff(
                '💳 Online Payment Submitted',
                "{$user['name']} submitted ₱" . number_format($amount, 2) . " via {$paymentMethod} for booking " . getBookingRef($booking) . ". Reference: {$referenceNo}. Awaiting your verification.",
                'payment',
                APP_URL . '/staff/payment_submissions.php'
            );

            logAudit($userId, 'SUBMIT_PAYMENT', "Customer submitted payment of ₱{$amount} via {$paymentMethod}, ref: {$referenceNo} for booking #{$bookingId}", 'payment_submissions');

            setFlash('success', "✓ Payment submission received! Our staff will verify your payment within 24 hours. You'll be notified once it's confirmed.");
            redirect(APP_URL . '/customer/bookings.php');

        } catch (Exception $e) {
            $errors[] = 'Submission failed: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header">
    <div>
        <h1><i class="fa-solid fa-money-bill-transfer" style="color:var(--accent-teal);"></i> Submit Online Payment</h1>
        <p>Upload your GCash / Online Banking proof for <strong><?= htmlspecialchars(getBookingRef($booking)) ?></strong></p>
    </div>
    <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back to Bookings
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <span class="alert-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
    <ul style="margin:0;padding-left:18px;">
        <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($existingPending): ?>
<div class="alert alert-warning" style="margin-bottom:20px;">
    <span class="alert-icon">⚠</span>
    You already have a <strong>pending submission</strong> of ₱<?= number_format($existingPending['amount'], 2) ?> 
    (<?= htmlspecialchars($existingPending['payment_method']) ?>, Ref: <?= htmlspecialchars($existingPending['reference_no']) ?>) 
    submitted on <?= date('M j, Y g:i A', strtotime($existingPending['created_at'])) ?> awaiting review.
    You may submit another if needed, but please avoid duplicate submissions.
</div>
<?php endif; ?>

<div class="grid-2" style="align-items:start;gap:24px;">

    <!-- Booking Summary Card -->
    <div class="card" style="border:1px solid rgba(78,205,196,0.25);">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-receipt" style="color:var(--accent-teal);"></i> Booking Summary</h2>
        </div>
        <div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px;">
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-secondary);font-size:0.88rem;">Booking Reference</span>
                <strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($booking)) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-secondary);font-size:0.88rem;">Package</span>
                <strong><?= htmlspecialchars($booking['package_name']) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-secondary);font-size:0.88rem;">Event Date</span>
                <strong><?= formatDate($booking['event_date']) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-secondary);font-size:0.88rem;">Total Amount</span>
                <strong style="color:var(--accent-gold);"><?= formatCurrency($total) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color);">
                <span style="color:var(--text-secondary);font-size:0.88rem;">Amount Paid</span>
                <strong style="color:#27ae60;"><?= formatCurrency($paid) ?></strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:12px 0;background:rgba(233,69,96,0.08);border-radius:8px;padding:12px 14px;margin-top:4px;">
                <span style="font-weight:700;font-size:0.95rem;">Outstanding Balance</span>
                <strong style="color:var(--accent-red);font-size:1.1rem;"><?= formatCurrency($balance) ?></strong>
            </div>
        </div>
        <div style="padding:12px 20px;background:rgba(78,205,196,0.05);border-top:1px solid rgba(78,205,196,0.15);border-radius:0 0 var(--radius) var(--radius);">
            <div style="font-size:0.8rem;color:var(--text-secondary);line-height:1.6;">
                <i class="fa-solid fa-circle-info" style="color:var(--accent-teal);"></i>
                Payment will be reviewed by our cashier within 24 hours. You'll receive an in-app notification upon approval.
            </div>
        </div>
    </div>

    <!-- Payment Submission Form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-paper-plane" style="color:var(--accent-gold);"></i> Payment Details</h2>
        </div>
        <form method="POST" enctype="multipart/form-data" style="padding:20px;" id="paymentForm">

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Payment Method <span style="color:var(--accent-red);">*</span></label>
                <select name="payment_method" class="form-input" required id="paymentMethodSelect">
                    <option value="gcash" <?= ($_POST['payment_method'] ?? '') === 'gcash' ? 'selected' : '' ?>>GCash</option>
                    <option value="paymaya" <?= ($_POST['payment_method'] ?? '') === 'paymaya' ? 'selected' : '' ?>>PayMaya / Maya</option>
                    <option value="bank_transfer" <?= ($_POST['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer (BPI / BDO / UnionBank)</option>
                    <option value="instapay" <?= ($_POST['payment_method'] ?? '') === 'instapay' ? 'selected' : '' ?>>InstaPay / PESONet</option>
                    <option value="other_online" <?= ($_POST['payment_method'] ?? '') === 'other_online' ? 'selected' : '' ?>>Other Online Method</option>
                </select>
            </div>

            <!-- GCash QR Info box -->
            <div id="gcashInfo" style="background:rgba(78,205,196,0.08);border:1px solid rgba(78,205,196,0.3);border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:0.85rem;line-height:1.7;">
                <div style="font-weight:700;color:var(--accent-teal);margin-bottom:4px;"><i class="fa-solid fa-circle-info"></i> GCash Payment Info</div>
                Please send payment to: <strong style="color:#fff;">09XX-XXX-XXXX</strong> (CraftHub Organizer)<br>
                Include your <strong>booking reference</strong> in the GCash remarks field.
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Payment Amount (₱) <span style="color:var(--accent-red);">*</span></label>
                <input type="number" name="amount" class="form-input" 
                       step="0.01" min="1" max="<?= $balance ?>"
                       value="<?= htmlspecialchars($_POST['amount'] ?? number_format($balance, 2, '.', '')) ?>"
                       placeholder="Enter amount sent" required id="amountInput">
                <small style="color:var(--text-muted);font-size:0.78rem;">Maximum: ₱<?= number_format($balance, 2) ?> (outstanding balance)</small>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Reference / Transaction Number <span style="color:var(--accent-red);">*</span></label>
                <input type="text" name="reference_no" class="form-input"
                       value="<?= htmlspecialchars($_POST['reference_no'] ?? '') ?>"
                       placeholder="e.g., GCash Ref: 123456789012" required
                       style="font-family:monospace;">
                <small style="color:var(--text-muted);font-size:0.78rem;">Found in your GCash / banking app transaction history.</small>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Payment Screenshot / Proof <span style="color:var(--accent-red);">*</span></label>
                <div id="dropZone" style="border:2px dashed rgba(78,205,196,0.4);border-radius:10px;padding:24px;text-align:center;cursor:pointer;background:rgba(78,205,196,0.03);transition:all 0.2s;position:relative;">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:2rem;color:var(--accent-teal);margin-bottom:8px;display:block;"></i>
                    <div style="font-size:0.9rem;color:var(--text-secondary);">
                        Drag & drop your screenshot here, or <span style="color:var(--accent-teal);font-weight:600;">click to browse</span>
                    </div>
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;">JPG, PNG, PDF — max 5MB</div>
                    <input type="file" name="proof_image" id="proofInput" accept="image/*,.pdf" required
                           style="position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;">
                </div>
                <div id="filePreview" style="display:none;margin-top:10px;"></div>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label">Additional Notes <span style="color:var(--text-muted);font-size:0.78rem;">(Optional)</span></label>
                <textarea name="notes" class="form-input" rows="3"
                          placeholder="e.g., This is my downpayment. Event is on April 15."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;font-size:1rem;padding:14px;" id="submitBtn">
                <i class="fa-solid fa-paper-plane"></i> Submit Payment for Review
            </button>
        </form>
    </div>
</div>

<script>
// Toggle payment info box
const methodSel = document.getElementById('paymentMethodSelect');
const gcashInfo = document.getElementById('gcashInfo');
methodSel.addEventListener('change', function() {
    gcashInfo.style.display = this.value === 'gcash' ? 'block' : 'none';
});

// File preview
const proofInput = document.getElementById('proofInput');
const filePreview = document.getElementById('filePreview');
const dropZone   = document.getElementById('dropZone');

proofInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const file = this.files[0];
        filePreview.style.display = 'block';
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = e => {
                filePreview.innerHTML = `
                    <div style="display:flex;align-items:center;gap:10px;background:rgba(39,174,96,0.08);border:1px solid rgba(39,174,96,0.3);border-radius:8px;padding:10px 14px;">
                        <img src="${e.target.result}" style="width:60px;height:60px;object-fit:cover;border-radius:6px;">
                        <div>
                            <div style="font-weight:600;font-size:0.88rem;">${file.name}</div>
                            <div style="font-size:0.78rem;color:var(--text-muted);">${(file.size/1024/1024).toFixed(2)} MB</div>
                            <div style="color:#27ae60;font-size:0.78rem;"><i class="fa-solid fa-check-circle"></i> Ready to upload</div>
                        </div>
                    </div>`;
            };
            reader.readAsDataURL(file);
        } else {
            filePreview.innerHTML = `
                <div style="display:flex;align-items:center;gap:10px;background:rgba(39,174,96,0.08);border:1px solid rgba(39,174,96,0.3);border-radius:8px;padding:10px 14px;">
                    <i class="fa-solid fa-file-pdf" style="font-size:2rem;color:#e74c3c;"></i>
                    <div>
                        <div style="font-weight:600;font-size:0.88rem;">${file.name}</div>
                        <div style="font-size:0.78rem;color:var(--text-muted);">${(file.size/1024/1024).toFixed(2)} MB</div>
                        <div style="color:#27ae60;font-size:0.78rem;"><i class="fa-solid fa-check-circle"></i> Ready to upload</div>
                    </div>
                </div>`;
        }
        dropZone.style.borderColor = 'rgba(39,174,96,0.5)';
        dropZone.style.background  = 'rgba(39,174,96,0.05)';
    }
});

// Form submit loading state
document.getElementById('paymentForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
