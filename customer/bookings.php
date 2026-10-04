<?php
$pageTitle = 'My Bookings';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);
requireApproved();

$user = getCurrentUser();
$db   = getDB();
$userId = $user['user_id'] ?? $user['id'];

// ── Handle Payment Proof Upload POST ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_payment_proof') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $amount    = (float)($_POST['amount'] ?? 0);
    $method    = sanitize($_POST['payment_method'] ?? 'gcash');
    $refNo     = sanitize($_POST['reference_no'] ?? '');
    $notes     = sanitize($_POST['notes'] ?? '');

    // Validate booking belongs to customer
    $chk = $db->prepare("SELECT * FROM bookings WHERE booking_id = ? AND customer_id = ?");
    $chk->execute([$bookingId, $userId]);
    $bk = $chk->fetch();

    if (!$bk) {
        setFlash('error', 'Booking not found.');
    } elseif ($amount <= 0) {
        setFlash('error', 'Payment amount must be greater than zero.');
    } elseif (empty($refNo)) {
        setFlash('error', 'Payment transaction reference number is required.');
    } elseif (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Please upload a clear screenshot or photo of your payment receipt.');
    } else {
        $uploadDir = __DIR__ . '/../uploads/payment_proofs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileInfo = pathinfo($_FILES['proof_image']['name']);
        $ext = strtolower($fileInfo['extension'] ?? '');
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        if (!in_array($ext, $allowedExts)) {
            setFlash('error', 'Only JPG, PNG, WEBP, or PDF files are allowed.');
        } else {
            $newFileName = 'proof_' . $bookingId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $destPath = $uploadDir . '/' . $newFileName;
            $relPath  = 'uploads/payment_proofs/' . $newFileName;

            if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $destPath)) {
                $ins = $db->prepare("
                    INSERT INTO payment_submissions (booking_id, customer_id, amount, payment_method, reference_no, proof_image, notes, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $ins->execute([$bookingId, $userId, $amount, $method, $refNo, $relPath, $notes]);

                $bRef = getBookingRef($bk);
                logAudit($userId, 'PAYMENT_SUBMITTED', "Customer submitted payment proof for booking #{$bRef} ({$refNo})", 'payment_submissions');

                notifyAdminsAndStaff(
                    "New Payment Proof Submitted",
                    "Customer uploaded proof of payment for booking #{$bRef}. Amount: " . formatCurrency($amount) . " via " . strtoupper($method) . " (Ref: {$refNo})",
                    "info",
                    APP_URL . "/staff/bills.php"
                );

                setFlash('success', "✓ Payment proof submitted successfully! Cashier will review and verify it shortly.");
                redirect(APP_URL . '/customer/bookings.php');
            } else {
                setFlash('error', 'Failed to save payment proof image. Please try again.');
            }
        }
    }
    redirect(APP_URL . '/customer/bookings.php');
}

$filter = sanitize($_GET['filter'] ?? 'all');
$where  = "WHERE b.customer_id = {$userId}";
$allowedFilters = ['all','pending','confirmed','completed','cancelled'];
if (!in_array($filter, $allowedFilters)) $filter = 'all';

$sql = "SELECT b.*, b.booking_id AS id, p.package_name AS package_name, v.venue_name AS venue_name,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS calc_paid,
               (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id AND payment_type = 'downpayment') AS downpayment_paid,
               (SELECT COUNT(*) FROM payment_submissions WHERE booking_id = b.booking_id AND status = 'pending') AS pending_submissions_count
        FROM bookings b
        JOIN packages p ON b.package_id = p.package_id
        LEFT JOIN venues v ON b.venue_id = v.venue_id
        WHERE b.customer_id = ?";
if ($filter !== 'all') $sql .= " AND b.status = ?";
$sql .= " ORDER BY b.created_at DESC";

$stmt = $db->prepare($sql);
$params = $filter !== 'all' ? [$userId, $filter] : [$userId];
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>My Bookings</h1>
        <p>Track all your craft event bookings, invoices, downpayments, and balances</p>
    </div>
    <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-primary">
        <i class="fa-solid fa-plus"></i> New Booking
    </a>
</div>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach (['all','pending','confirmed','completed','cancelled'] as $f): ?>
        <a href="?filter=<?= $f ?>" class="btn btn-sm <?= $filter === $f ? 'btn-primary' : 'btn-secondary' ?>">
            <?= ucfirst($f) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-calendar-xmark"></i>
            <h3>No Bookings Found</h3>
            <p><?= $filter !== 'all' ? "No {$filter} bookings." : "You haven't made any bookings yet." ?></p>
            <a href="<?= APP_URL ?>/customer/packages.php" class="btn btn-primary">Browse Packages</a>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" id="bookingsTable">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Package / Venue</th>
                        <th>Event Date</th>
                        <th>Total Amount</th>
                        <th>Total Paid</th>
                        <th>Remaining Balance</th>
                        <th>Payment Due</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): 
                        $total       = (float)($b['total_amount'] ?? 0);
                        $paid        = (float)($b['calc_paid'] > 0 ? $b['calc_paid'] : ($b['amount_paid'] ?? 0));
                        $balance     = max(0.0, $total - $paid);
                        $payStatus   = ($paid >= $total && $total > 0) ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                        $dueDate     = getBookingPaymentDueDate($b);
                        $dueTs       = strtotime($dueDate);
                        $daysLeft    = (int)round(($dueTs - strtotime(date('Y-m-d'))) / 86400);
                        $hasPendingProof = (int)($b['pending_submissions_count'] ?? 0) > 0;
                    ?>
                    <tr style="white-space:nowrap;">
                        <td style="white-space:nowrap;">
                            <span class="ref-chip"><?= htmlspecialchars(getBookingRef($b)) ?></span>
                            <div style="margin-top:5px;">
                                <?php if (strtolower($b['status']) === 'pending'): ?>
                                    <span class="badge badge-warning" style="font-size:0.7rem;padding:2px 7px;" title="Awaiting Cashier counter review & approval">
                                        <i class="fa-solid fa-clock"></i> Awaiting Cashier Approval
                                    </span>
                                <?php else: ?>
                                    <?= statusBadge($b['status']) ?>
                                    <?php if (!empty($b['approved_at'])): ?>
                                        <div style="font-size:0.68rem;color:#27ae60;margin-top:3px;display:flex;align-items:center;gap:3px;" title="Approved on <?= date('M d, Y g:i A', strtotime($b['approved_at'])) ?>">
                                            <i class="fa-solid fa-circle-check"></i>
                                            <span>Approved</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (strtolower($b['status']) === 'cancelled' && !empty($b['cancellation_reason'])): ?>
                                        <div style="font-size:0.7rem;color:#e94560;margin-top:3px;max-width:180px;white-space:normal;line-height:1.2;">
                                            <i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($b['cancellation_reason']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="white-space:nowrap;">
                            <div style="font-weight:600;"><?= htmlspecialchars($b['package_name']) ?></div>
                            <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($b['venue_name'] ?? 'No Venue') ?></div>
                        </td>
                        <td style="white-space:nowrap;">
                            <div style="font-weight:500;"><?= formatDate($b['event_date']) ?></div>
                            <div style="font-size:0.78rem;color:var(--text-muted);"><?= date('g:i A', strtotime($b['event_time'] ?? '09:00:00')) ?></div>
                        </td>
                        <td style="white-space:nowrap;">
                            <div style="font-weight:700;"><?= formatCurrency($total) ?></div>
                            <?php if (!empty($b['payment_plan'])): ?>
                                <div style="margin-top:3px;">
                                    <span class="badge" style="font-size:0.68rem;padding:2px 6px;background:<?= $b['payment_plan'] === 'full' ? 'rgba(46,204,113,0.15)' : 'rgba(78,205,196,0.15)' ?>;color:<?= $b['payment_plan'] === 'full' ? '#2ecc71' : 'var(--accent-teal)' ?>;border:1px solid <?= $b['payment_plan'] === 'full' ? 'rgba(46,204,113,0.35)' : 'rgba(78,205,196,0.35)' ?>;font-weight:600;">
                                        <?= $b['payment_plan'] === 'full' ? 'Full Payment' : 'Downpayment' ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if ((float)($b['discount_amount'] ?? 0) > 0): ?>
                                <div style="font-size:0.7rem;color:#2ecc71;margin-top:2px;">
                                    <i class="fa-solid fa-tag"></i> Saved <?= formatCurrency($b['discount_amount']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="color:#27ae60;font-weight:700;white-space:nowrap;"><?= formatCurrency($paid) ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($balance > 0): ?>
                                <strong style="color:var(--accent-red);"><?= formatCurrency($balance) ?></strong>
                                <?php if ($hasPendingProof): ?>
                                    <div style="margin-top:3px;">
                                        <span class="badge badge-warning" style="font-size:0.68rem;padding:2px 6px;">
                                            <i class="fa-solid fa-hourglass-half"></i> Proof Under Review
                                        </span>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Fully Paid</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($balance <= 0): ?>
                                <span class="badge badge-success" style="font-size:0.75rem;"><i class="fa-solid fa-check"></i> Settled</span>
                            <?php elseif ($daysLeft < 0): ?>
                                <div><strong style="color:#e94560;font-size:0.88rem;"><?= date('M d, Y', $dueTs) ?></strong></div>
                                <span class="badge badge-danger" style="font-size:0.72rem;padding:2px 6px;">Overdue (<?= abs($daysLeft) ?>d)</span>
                            <?php elseif ($daysLeft === 0): ?>
                                <div><strong style="color:#f5a623;font-size:0.88rem;"><?= date('M d, Y', $dueTs) ?></strong></div>
                                <span class="badge badge-warning" style="font-size:0.72rem;padding:2px 6px;">Due Today</span>
                            <?php elseif ($daysLeft <= 7): ?>
                                <div><strong style="color:#f5a623;font-size:0.88rem;"><?= date('M d, Y', $dueTs) ?></strong></div>
                                <span class="badge badge-warning" style="font-size:0.72rem;padding:2px 6px;">Due in <?= $daysLeft ?> days</span>
                            <?php else: ?>
                                <div style="font-size:0.85rem;color:var(--text-primary);"><?= date('M d, Y', $dueTs) ?></div>
                                <span style="font-size:0.72rem;color:var(--text-muted);"><?= $daysLeft ?> days left</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;"><?= statusBadge($payStatus) ?></td>
                        <td style="white-space:nowrap;">
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                                <!-- View Statement of Account / Invoice -->
                                <a href="<?= APP_URL ?>/invoice.php?id=<?= $b['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="View Statement of Account / Invoice" style="color:var(--accent-teal);border-color:rgba(78,205,196,0.3);white-space:nowrap;">
                                    <i class="fa-solid fa-file-invoice"></i> SOA
                                </a>

                                <?php if ($balance > 0 && in_array(strtolower($b['status']), ['confirmed','paid'])): ?>
                                    <!-- Dedicated Submit Payment Page -->
                                    <a href="<?= APP_URL ?>/customer/submit_payment.php?id=<?= $b['id'] ?>"
                                       class="btn btn-warning btn-sm" style="white-space:nowrap;" title="Submit Online Payment Proof">
                                        <i class="fa-solid fa-money-bill-transfer"></i> Pay Online
                                    </a>
                                <?php elseif ($balance > 0 && strtolower($b['status']) !== 'cancelled'): ?>
                                    <button type="button" class="btn btn-warning btn-sm"
                                            onclick="openPayProofModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes(getBookingRef($b))) ?>', <?= $balance ?>)"
                                            style="white-space:nowrap;" title="Upload GCash / Bank Transfer Proof">
                                        <i class="fa-solid fa-upload"></i> Pay Proof
                                    </button>
                                <?php endif; ?>

                                <a href="<?= APP_URL ?>/booking_images.php?booking_id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm" style="white-space:nowrap;" title="View &amp; Upload Event Photos">
                                    <i class="fa-solid fa-camera"></i> Photos
                                </a>

                                <?php if (strtolower($b['status']) === 'pending'): ?>
                                    <a href="<?= APP_URL ?>/customer/cancel_booking.php?id=<?= $b['id'] ?>"
                                       class="btn btn-danger btn-sm"
                                       style="white-space:nowrap;"
                                       data-confirm="Cancel booking <?= htmlspecialchars(getBookingRef($b)) ?>?">
                                        <i class="fa-solid fa-xmark"></i> Cancel
                                    </a>
                                <?php endif; ?>

                                <?php if (strtolower($b['status']) === 'completed'): ?>
                                    <?php
                                    $hasReview = false;
                                    try {
                                        $revChk = $db->prepare("SELECT 1 FROM reviews WHERE booking_id = ? AND customer_id = ? LIMIT 1");
                                        $revChk->execute([$b['id'], $userId]);
                                        $hasReview = (bool)$revChk->fetchColumn();
                                    } catch (Exception $e) {}
                                    ?>
                                    <?php if (!$hasReview): ?>
                                    <a href="<?= APP_URL ?>/customer/review.php?id=<?= $b['id'] ?>"
                                       class="btn btn-sm" style="background:linear-gradient(135deg,#f5a623,#f5c623);color:#0f0f1a;font-weight:700;white-space:nowrap;">
                                        <i class="fa-solid fa-star"></i> Leave Review
                                    </a>
                                    <?php else: ?>
                                    <span class="badge badge-success" style="font-size:0.72rem;">
                                        <i class="fa-solid fa-star"></i> Reviewed
                                    </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ══ PAYMENT PROOF UPLOAD MODAL ══ -->
<div class="modal-overlay" id="payProofModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(6px);">
    <div class="modal" style="background:#161626;border:1px solid rgba(255,255,255,0.1);border-radius:20px;max-width:500px;width:90%;padding:26px;box-shadow:0 24px 60px rgba(0,0,0,0.8);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;border-bottom:1px solid rgba(255,255,255,0.08);padding-bottom:12px;">
            <h3 style="margin:0;font-size:1.2rem;color:var(--accent-gold);"><i class="fa-solid fa-mobile-screen-button"></i> Submit Payment Proof</h3>
            <button type="button" onclick="closePayProofModal()" style="background:none;border:none;color:#aaa;font-size:1.4rem;cursor:pointer;">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_payment_proof">
            <input type="hidden" name="booking_id" id="proofBookingId">

            <div style="background:rgba(245,166,35,0.08);border:1px solid rgba(245,166,35,0.25);border-radius:12px;padding:12px 16px;margin-bottom:18px;">
                <div style="font-size:0.8rem;color:var(--text-muted);text-transform:uppercase;">Booking Reference</div>
                <div style="font-size:1.1rem;font-weight:700;color:#fff;" id="proofBookingRefText">BK-0000</div>
                <div style="font-size:0.82rem;color:var(--accent-red);margin-top:4px;">Remaining Balance: <strong id="proofBalanceText">₱ 0.00</strong></div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label" style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;display:block;">Payment Method</label>
                <select name="payment_method" class="form-control" required style="width:100%;padding:10px 14px;background:#0f0f1a;border:1px solid rgba(255,255,255,0.15);border-radius:10px;color:#fff;">
                    <option value="gcash">GCash (0917-123-4567 / CraftHub Admin)</option>
                    <option value="maya">Maya (0917-123-4567)</option>
                    <option value="bank_transfer">BDO Bank Transfer (Acct: 0012-3456-7890)</option>
                    <option value="bpi">BPI Bank Transfer (Acct: 0098-7654-3210)</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label" style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;display:block;">Amount Deposited / Sent (₱)</label>
                <input type="number" step="0.01" name="amount" id="proofAmountInput" class="form-control" placeholder="e.g. 5000" required style="width:100%;padding:10px 14px;background:#0f0f1a;border:1px solid rgba(255,255,255,0.15);border-radius:10px;color:#fff;">
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label" style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;display:block;">Reference Number / Transaction ID</label>
                <input type="text" name="reference_no" class="form-control" placeholder="e.g. 1002 9384 1029" required style="width:100%;padding:10px 14px;background:#0f0f1a;border:1px solid rgba(255,255,255,0.15);border-radius:10px;color:#fff;">
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label" style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;display:block;">Upload Screenshot / Receipt Photo</label>
                <input type="file" name="proof_image" accept="image/*,.pdf" class="form-control" required style="width:100%;padding:8px 12px;background:#0f0f1a;border:1px solid rgba(255,255,255,0.15);border-radius:10px;color:#fff;">
                <small style="color:var(--text-muted);font-size:0.75rem;margin-top:4px;display:block;">PNG, JPG, or PDF (Max 10MB)</small>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label" style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;display:block;">Additional Notes <span style="color:var(--text-muted);">(optional)</span></label>
                <input type="text" name="notes" class="form-control" placeholder="e.g. Sent via my sister's GCash" style="width:100%;padding:10px 14px;background:#0f0f1a;border:1px solid rgba(255,255,255,0.15);border-radius:10px;color:#fff;">
            </div>

            <div style="display:flex;gap:12px;justify-content:flex-end;">
                <button type="button" onclick="closePayProofModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit for Verification</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayProofModal(bookingId, ref, balance) {
    document.getElementById('proofBookingId').value = bookingId;
    document.getElementById('proofBookingRefText').textContent = '#' + ref;
    document.getElementById('proofBalanceText').textContent = '₱ ' + Number(balance).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('proofAmountInput').value = balance;
    document.getElementById('proofAmountInput').max = balance;
    document.getElementById('payProofModal').style.display = 'flex';
}
function closePayProofModal() {
    document.getElementById('payProofModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
