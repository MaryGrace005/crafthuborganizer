<?php
$pageTitle = 'Payment Submissions Review';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);

$db     = getDB();
$user   = getCurrentUser();
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

$filterStatus = $_GET['status'] ?? 'pending';
$search       = trim($_GET['search'] ?? '');

// POST: Approve or Reject a submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']      ?? '';
    $subId   = (int)($_POST['sub_id'] ?? 0);
    $rejectReason = trim($_POST['reject_reason'] ?? '');

    if ($subId > 0 && in_array($action, ['approve', 'reject'])) {
        // Get submission + booking info
        $sub = $db->prepare("
            SELECT ps.*, b.customer_id, b.booking_id, b.total_amount, b.status AS booking_status,
                   u.name AS customer_name, b.booking_reference
            FROM payment_submissions ps
            JOIN bookings b ON ps.booking_id = b.booking_id
            JOIN users u ON b.customer_id = u.user_id
            WHERE ps.submission_id = ? AND ps.status = 'pending'
        ");
        $sub->execute([$subId]);
        $submission = $sub->fetch();

        if ($submission) {
            if ($action === 'approve') {
                try {
                    $db->beginTransaction();

                    // Generate OR number
                    $orYear  = date('Y');
                    $orCount = (int)$db->query("SELECT COUNT(*) FROM payments WHERE YEAR(payment_date) = $orYear")->fetchColumn() + 1;
                    $orNum   = 'OR-' . $orYear . '-' . str_pad($orCount, 4, '0', STR_PAD_LEFT);

                    // Insert into payments
                    $insPayment = $db->prepare("
                        INSERT INTO payments (booking_id, cashier_id, amount_paid, payment_type, payment_method, reference_no, or_number, notes, payment_date)
                        VALUES (?, ?, ?, 'balance', ?, ?, ?, ?, NOW())
                    ");
                    $paid    = (float)$submission['amount'];
                    $total   = (float)$submission['total_amount'];
                    $prevPaid = (float)$db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = ?")->execute([$submission['booking_id']]) ? 0 : 0;
                    $paidSoFar = (float)$db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = ?")->execute([$submission['booking_id']]);

                    // Re-fetch paid amount
                    $paidQ = $db->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = ?");
                    $paidQ->execute([$submission['booking_id']]);
                    $paidSoFar = (float)$paidQ->fetchColumn();

                    $payType = ($paidSoFar + $paid >= $total - 0.01) ? 'full' : (($paidSoFar > 0) ? 'balance' : 'downpayment');

                    $insPayment->execute([
                        $submission['booking_id'],
                        $userId,
                        $paid,
                        $submission['payment_method'],
                        $submission['reference_no'],
                        $orNum,
                        'Online payment verified by ' . ($user['name'] ?? 'Staff') . '. Ref: ' . $submission['reference_no']
                    ]);
                    $paymentId = (int)$db->lastInsertId();

                    // Also upload proof to booking_images
                    if (!empty($submission['proof_image'])) {
                        $imgStmt = $db->prepare("
                            INSERT INTO booking_images (booking_id, uploaded_by, image_type, image_path, original_name, mime_type, caption, is_public)
                            VALUES (?, ?, 'payment_proof', ?, ?, 'image/jpeg', ?, 1)
                        ");
                        $imgStmt->execute([
                            $submission['booking_id'],
                            $submission['customer_id'],
                            $submission['proof_image'],
                            basename($submission['proof_image']),
                            'Online payment proof - ' . $submission['reference_no']
                        ]);
                    }

                    // Update submission
                    $db->prepare("
                        UPDATE payment_submissions
                        SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), payment_id = ?
                        WHERE submission_id = ?
                    ")->execute([$userId, $paymentId, $subId]);

                    // Update booking payment status
                    updateBookingPaymentStatus($submission['booking_id']);

                    $db->commit();

                    // Notify customer
                    try {
                        $ref = $submission['booking_reference'] ?? ('BK-' . $submission['booking_id']);
                        createNotification(
                            $submission['customer_id'],
                            '✅ Payment Verified',
                            "Your payment of ₱" . number_format($paid, 2) . " via {$submission['payment_method']} (Ref: {$submission['reference_no']}) for booking {$ref} has been approved! OR Number: {$orNum}.",
                            'success',
                            APP_URL . '/customer/payment_history.php'
                        );

                        // Send email receipt
                        require_once __DIR__ . '/../includes/mailer.php';
                        sendPaymentReceiptEmail($paymentId);
                    } catch (Throwable $notifEx) {
                        error_log('Notification/Email error in payment approval: ' . $notifEx->getMessage());
                    }

                    logAudit($userId, 'APPROVE_ONLINE_PAYMENT', "Approved online payment submission #{$subId} for booking #{$submission['booking_id']}, amount ₱{$paid}, OR: {$orNum}", 'payment_submissions');
                    setFlash('success', "✓ Payment approved! OR #{$orNum} generated. Customer has been notified.");

                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    setFlash('error', 'Failed to approve payment: ' . $e->getMessage());
                }

            } elseif ($action === 'reject') {
                if (empty($rejectReason)) {
                    setFlash('error', 'Please provide a rejection reason.');
                } else {
                    $db->prepare("
                        UPDATE payment_submissions
                        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?
                        WHERE submission_id = ?
                    ")->execute([$userId, $rejectReason, $subId]);

                    $ref = $submission['booking_reference'] ?? ('BK-' . $submission['booking_id']);
                    createNotification(
                        $submission['customer_id'],
                        '❌ Payment Submission Rejected',
                        "Your payment submission for booking {$ref} (Ref: {$submission['reference_no']}) was not approved. Reason: {$rejectReason}. Please resubmit with the correct proof.",
                        'error',
                        APP_URL . '/customer/submit_payment.php?id=' . $submission['booking_id']
                    );

                    logAudit($userId, 'REJECT_ONLINE_PAYMENT', "Rejected submission #{$subId} for booking #{$submission['booking_id']}. Reason: {$rejectReason}", 'payment_submissions');
                    setFlash('success', 'Submission rejected. Customer has been notified.');
                }
            }
        }
    }

    redirect(APP_URL . '/staff/payment_submissions.php');
}

// Fetch submissions
$whereClause = " WHERE 1=1";
$params = [];

if (in_array($filterStatus, ['pending', 'approved', 'rejected'])) {
    $whereClause .= " AND ps.status = ?";
    $params[] = $filterStatus;
}

if ($search) {
    $whereClause .= " AND (u.name LIKE ? OR ps.reference_no LIKE ? OR b.booking_reference LIKE ?)";
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s]);
}

$submissionsStmt = $db->prepare("
    SELECT ps.*, u.name AS customer_name, u.email AS customer_email,
           b.booking_reference, b.event_date, b.total_amount, p.package_name,
           rev.name AS reviewer_name
    FROM payment_submissions ps
    JOIN users u ON ps.customer_id = u.user_id
    JOIN bookings b ON ps.booking_id = b.booking_id
    JOIN packages p ON b.package_id = p.package_id
    LEFT JOIN users rev ON ps.reviewed_by = rev.user_id
    {$whereClause}
    ORDER BY ps.created_at DESC
    LIMIT 100
");
$submissionsStmt->execute($params);
$submissions = $submissionsStmt->fetchAll();

// Count pending
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM payment_submissions WHERE status = 'pending'")->fetchColumn();

require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header">
    <div>
        <h1><i class="fa-solid fa-money-bill-transfer" style="color:var(--accent-teal);"></i> Online Payment Submissions</h1>
        <p>Review customer GCash / online payment proofs</p>
    </div>
    <?php if ($pendingCount > 0): ?>
    <span class="badge badge-warning" style="font-size:0.9rem;padding:8px 14px;">
        <?= $pendingCount ?> Pending Review
    </span>
    <?php endif; ?>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
    <div style="display:flex;gap:6px;">
        <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $val=>$label): ?>
        <a href="?status=<?= $val ?><?= $search ? '&search='.urlencode($search) : '' ?>"
           class="btn btn-sm <?= ($filterStatus === $val || ($val === 'all' && !in_array($filterStatus, ['pending','approved','rejected']))) ? 'btn-primary' : 'btn-secondary' ?>">
            <?= $label ?>
            <?php if ($val === 'pending' && $pendingCount > 0): ?>
            <span class="badge badge-warning" style="margin-left:4px;"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <input type="text" name="search" class="form-input" style="max-width:260px;"
           placeholder="Search name, ref no, booking..." value="<?= htmlspecialchars($search) ?>">
    <?php if (!in_array($filterStatus, ['pending','approved','rejected']) && $filterStatus !== 'all'): ?>
    <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
    <?php else: ?>
    <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
    <?php endif; ?>
    <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-search"></i></button>
    <?php if ($search): ?>
    <a href="?status=<?= $filterStatus ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-xmark"></i> Clear</a>
    <?php endif; ?>
</form>

<?php if (empty($submissions)): ?>
<div class="card">
    <div class="empty-state" style="padding:50px;">
        <i class="fa-solid fa-inbox" style="color:var(--accent-teal);"></i>
        <p>No <?= $filterStatus !== 'all' ? $filterStatus : '' ?> submissions found<?= $search ? " for \"$search\"" : '' ?>.</p>
    </div>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:16px;">
<?php foreach ($submissions as $sub):
    $statusColor = ['pending' => '#f5a623', 'approved' => '#27ae60', 'rejected' => '#e94560'][$sub['status']] ?? '#aaa';
    $statusIcon  = ['pending' => 'fa-clock', 'approved' => 'fa-check-circle', 'rejected' => 'fa-times-circle'][$sub['status']] ?? 'fa-circle';
?>
<div class="card" style="border-left:4px solid <?= $statusColor ?>;">
    <div style="padding:18px 20px;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start;">
        <!-- Left: Info -->
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
                <span style="font-weight:800;font-size:1rem;color:#fff;"><?= htmlspecialchars($sub['customer_name']) ?></span>
                <span style="font-size:0.78rem;color:var(--accent-teal);font-weight:700;"><?= htmlspecialchars($sub['booking_reference'] ?? 'BK-'.$sub['booking_id']) ?></span>
                <span style="font-size:0.75rem;color:<?= $statusColor ?>;background:<?= $statusColor ?>22;padding:2px 8px;border-radius:20px;font-weight:700;">
                    <i class="fa-solid <?= $statusIcon ?>"></i> <?= ucfirst($sub['status']) ?>
                </span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;font-size:0.83rem;color:var(--text-secondary);margin-bottom:10px;">
                <div>📦 <strong style="color:#fff;"><?= htmlspecialchars($sub['package_name']) ?></strong></div>
                <div>📅 Event: <strong style="color:#fff;"><?= formatDate($sub['event_date']) ?></strong></div>
                <div>💰 Submitted: <strong style="color:var(--accent-gold);">₱<?= number_format($sub['amount'], 2) ?></strong></div>
                <div>💳 Method: <strong style="color:#fff;"><?= htmlspecialchars(ucwords(str_replace('_',' ',$sub['payment_method']))) ?></strong></div>
                <div>🔖 Ref No: <strong style="color:#fff;font-family:monospace;"><?= htmlspecialchars($sub['reference_no']) ?></strong></div>
                <div>⏰ Submitted: <strong style="color:#fff;"><?= date('M j, Y g:i A', strtotime($sub['created_at'])) ?></strong></div>
            </div>

            <?php if (!empty($sub['notes'])): ?>
            <div style="font-size:0.82rem;color:var(--text-secondary);font-style:italic;margin-bottom:8px;">
                📝 <?= htmlspecialchars($sub['notes']) ?>
            </div>
            <?php endif; ?>

            <?php if ($sub['status'] === 'rejected' && !empty($sub['rejection_reason'])): ?>
            <div style="background:rgba(233,69,96,0.1);border:1px solid rgba(233,69,96,0.3);border-radius:8px;padding:8px 12px;font-size:0.82rem;color:var(--accent-red);">
                <strong>Rejection Reason:</strong> <?= htmlspecialchars($sub['rejection_reason']) ?>
            </div>
            <?php endif; ?>

            <?php if ($sub['status'] === 'approved'): ?>
            <div style="font-size:0.82rem;color:#27ae60;">
                <i class="fa-solid fa-check-circle"></i>
                Approved by <?= htmlspecialchars($sub['reviewer_name'] ?? 'Staff') ?> on <?= date('M j, Y g:i A', strtotime($sub['reviewed_at'])) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right: Actions & Proof -->
        <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;min-width:160px;">

            <!-- Payment proof thumbnail -->
            <?php if (!empty($sub['proof_image'])): ?>
            <?php $proofUrl = APP_URL . '/' . htmlspecialchars($sub['proof_image']); ?>
            <?php if (strtolower(pathinfo($sub['proof_image'], PATHINFO_EXTENSION)) === 'pdf'): ?>
            <a href="<?= $proofUrl ?>" target="_blank" class="btn btn-secondary btn-sm" style="font-size:0.8rem;">
                <i class="fa-solid fa-file-pdf" style="color:#e74c3c;"></i> View PDF Proof
            </a>
            <?php else: ?>
            <a href="<?= $proofUrl ?>" target="_blank" style="display:block;">
                <img src="<?= $proofUrl ?>" alt="Payment Proof"
                     style="width:120px;height:80px;object-fit:cover;border-radius:8px;border:2px solid rgba(78,205,196,0.3);transition:transform 0.2s;"
                     onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
            </a>
            <a href="<?= $proofUrl ?>" target="_blank" style="font-size:0.78rem;color:var(--accent-teal);text-decoration:none;">
                <i class="fa-solid fa-expand"></i> View Full
            </a>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Action Buttons for Pending -->
            <?php if ($sub['status'] === 'pending'): ?>
            <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">
                <!-- Approve Button -->
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="sub_id" value="<?= $sub['submission_id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm" style="width:100%;font-weight:700;"
                            onclick="return confirm('Approve ₱<?= number_format($sub['amount'], 2) ?> payment from <?= addslashes($sub['customer_name']) ?>?')">
                        <i class="fa-solid fa-check"></i> Approve
                    </button>
                </form>

                <!-- Reject Button (toggle form) -->
                <button type="button" class="btn btn-danger btn-sm" style="width:100%;"
                        onclick="document.getElementById('rejectForm<?= $sub['submission_id'] ?>').style.display='block';this.style.display='none';">
                    <i class="fa-solid fa-xmark"></i> Reject
                </button>

                <div id="rejectForm<?= $sub['submission_id'] ?>" style="display:none;margin-top:4px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="sub_id" value="<?= $sub['submission_id'] ?>">
                        <textarea name="reject_reason" class="form-input" rows="2" placeholder="State rejection reason..." required
                                  style="font-size:0.8rem;margin-bottom:6px;resize:vertical;"></textarea>
                        <button type="submit" class="btn btn-danger btn-sm" style="width:100%;font-size:0.8rem;">
                            <i class="fa-solid fa-ban"></i> Confirm Rejection
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <a href="<?= APP_URL ?>/staff/process_payment.php?id=<?= $sub['booking_id'] ?>" class="btn btn-secondary btn-sm" style="font-size:0.78rem;">
                <i class="fa-solid fa-eye"></i> View Booking
            </a>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
