<?php
// ============================================================
//  Bills & Accounts Receivable — CraftHub Organizer (Staff/Cashier)
// ============================================================
$pageTitle = 'Bills & Accounts Receivable';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);
$db = getDB();
ensureApprovalColumns($db);

// ── POST actions (Approve & Quick-pay) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Quick-approve pending booking directly from Bills (Approve only)
    if ($action === 'approve_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $cashierId = $_SESSION['user_id'] ?? 0;
        $chk = $db->prepare("SELECT b.*, u.name AS customer_name FROM bookings b JOIN users u ON b.customer_id = u.user_id WHERE b.booking_id = ?");
        $chk->execute([$bookingId]);
        $bk = $chk->fetch();

        if ($bk) {
            $ref = getBookingRef($bk);
            ensureApprovalColumns($db);
            $now = date('Y-m-d H:i:s');

            $db->prepare("UPDATE bookings SET status = 'Confirmed', approved_at = ?, approved_by = ? WHERE booking_id = ?")
               ->execute([$now, $cashierId, $bookingId]);
            updateBookingPaymentStatus($bookingId);

            $timeFormatted = date('M d, Y g:i A', strtotime($now));
            logAudit($cashierId, 'APPROVE_BOOKING', "Cashier approved booking #{$bookingId} ({$ref}) on {$timeFormatted} from Bills", 'bookings');
            setFlash('success', "✓ Booking <strong>{$ref}</strong> approved on <strong>{$timeFormatted}</strong>! Payment can now be collected.");

            $qs = !empty($_GET['search']) ? '?search=' . urlencode($_GET['search']) : '';
            redirect(APP_URL . '/staff/bills.php' . $qs);
        } else {
            setFlash('error', 'Booking not found.');
            $qs = !empty($_GET['search']) ? '?search=' . urlencode($_GET['search']) : '';
            redirect(APP_URL . '/staff/bills.php' . $qs);
        }
    }

    if ($action === 'mark_paid') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $amount    = round((float)($_POST['amount'] ?? 0), 2);
        $method    = sanitize($_POST['payment_method'] ?? 'cash');
        $cashierId = $_SESSION['user_id'];

        if ($bookingId > 0 && $amount > 0) {
            // Real-time check: Cashier cannot collect payment if booking is Pending or Cancelled
            $chkStmt = $db->prepare("SELECT status, total_amount, (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = bookings.booking_id) AS paid_so_far FROM bookings WHERE booking_id = ?");
            $chkStmt->execute([$bookingId]);
            $currBooking = $chkStmt->fetch();

            if (!$currBooking) {
                setFlash('error', 'Booking not found.');
                redirect(APP_URL . '/staff/bills.php');
            }

            if (strtolower($currBooking['status']) === 'pending') {
                setFlash('error', 'Cannot collect payment: Booking is still Pending. Please click Approve first to confirm the booking.');
                redirect(APP_URL . '/staff/bills.php');
            }

            if (strtolower($currBooking['status']) === 'cancelled') {
                setFlash('error', 'Cannot collect payment: This booking has been cancelled.');
                redirect(APP_URL . '/staff/bills.php');
            }

            $remainingBalance = round(max(0.0, (float)$currBooking['total_amount'] - (float)$currBooking['paid_so_far']), 2);
            if ($remainingBalance <= 0.005) {
                setFlash('info', 'This booking is already fully paid.');
                redirect(APP_URL . '/staff/bills.php');
            }
            if ($amount > $remainingBalance) {
                $amount = $remainingBalance;
            }

            $orNumber = 'OR-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $ins = $db->prepare("INSERT INTO payments (booking_id, cashier_id, amount_paid, payment_method, or_number, payment_date, notes)
                          VALUES (?, ?, ?, ?, ?, NOW(), 'Quick pay from Cashier Bills page')");
            $ins->execute([$bookingId, $cashierId, $amount, $method, $orNumber]);
            $newPayId = (int)$db->lastInsertId();

            try {
                $upd = $db->prepare("UPDATE bookings SET amount_paid = COALESCE(amount_paid, 0) + ? WHERE booking_id = ?");
                $upd->execute([$amount, $bookingId]);
            } catch (PDOException $e) {}
            updateBookingPaymentStatus($bookingId);

            logAudit($cashierId, 'PAYMENT', "Recorded payment of ₱{$amount} for booking #{$bookingId}", 'payments');
            setFlash('success', 'Payment of ' . formatCurrency($amount) . ' recorded successfully! <a href="' . APP_URL . '/receipt.php?id=' . $newPayId . '" target="_blank" style="color:#fff;text-decoration:underline;margin-left:8px;font-weight:700;"><i class="fa-solid fa-receipt"></i> View Receipt (' . $orNumber . ')</a>');
        } // end if bookingId > 0 && amount > 0
        redirect(APP_URL . '/staff/bills.php');
    } // end if action === 'mark_paid'
}

// ── Filters ────────────────────────────────────────────────
$search     = sanitize($_GET['search'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? 'outstanding');

// ── Query outstanding balances ─────────────────────────────
$sql = "
    SELECT
        b.booking_id,
        b.booking_reference,
        b.total_amount,
        b.event_date,
        b.payment_due_date,
        b.status AS booking_status,
        b.approved_at,
        b.created_at,
        u.name   AS customer_name,
        u.email  AS customer_email,
        u.contact_no,
        p.package_name,
        COALESCE(SUM(pay.amount_paid), 0) AS total_paid,
        MAX(pay.payment_id) AS latest_payment_id
    FROM bookings b
    JOIN users    u ON b.customer_id = u.user_id
    JOIN packages p ON b.package_id  = p.package_id
    LEFT JOIN payments pay ON pay.booking_id = b.booking_id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR b.booking_reference LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql .= " GROUP BY b.booking_id";

if ($statusFilter === 'outstanding') {
    $sql .= " HAVING (b.total_amount - COALESCE(SUM(pay.amount_paid),0)) > 0 AND b.status NOT IN ('Cancelled')";
} elseif ($statusFilter === 'paid') {
    $sql .= " HAVING (b.total_amount - COALESCE(SUM(pay.amount_paid),0)) <= 0";
}

$sql .= " ORDER BY b.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll();

// ── Summary stats ──────────────────────────────────────────
$totalOutstanding = 0;
$totalCollected   = 0;
foreach ($bills as $bill) {
    $balance = round(max(0.0, (float)$bill['total_amount'] - (float)$bill['total_paid']), 2);
    $totalOutstanding += $balance;
    $totalCollected   += (float)$bill['total_paid'];
}
?>
<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- Page Header -->
<div class="page-header" style="background:linear-gradient(135deg,rgba(245,166,35,0.08),rgba(78,205,196,0.05));border:1px solid rgba(245,166,35,0.2);border-radius:20px;padding:28px 32px;margin-bottom:24px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#f5a623,#4ecdc4,#27ae60);"></div>
    <div>
        <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(245,166,35,0.12);border:1px solid rgba(245,166,35,0.3);color:#f5a623;padding:5px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">
            <i class="fa-solid fa-file-invoice-dollar"></i> Cashier Billing &amp; Receivables
        </div>
        <h1 style="font-family:'Outfit',sans-serif;font-size:1.9rem;font-weight:800;margin-bottom:4px;">Bills &amp; Customer Balances</h1>
        <p style="color:var(--text-secondary);font-size:0.92rem;">Collect payments, view remaining balances, and manage customer bills.</p>
    </div>
    <div style="display:flex;gap:24px;flex-shrink:0;">
        <div style="text-align:center;">
            <div style="font-family:'Outfit',sans-serif;font-size:1.5rem;font-weight:800;color:#e94560;"><?= formatCurrency($totalOutstanding) ?></div>
            <div style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Outstanding</div>
        </div>
        <div style="text-align:center;">
            <div style="font-family:'Outfit',sans-serif;font-size:1.5rem;font-weight:800;color:#27ae60;"><?= formatCurrency($totalCollected) ?></div>
            <div style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Total Collected</div>
        </div>
    </div>
</div>

<?php displayFlash(); ?>

<!-- Search + Filter Bar -->
<div class="card" style="margin-bottom:20px;">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:4px 0;">
        <div class="form-group" style="margin:0;flex:1;min-width:220px;">
            <label class="form-label"><i class="fa-solid fa-magnifying-glass"></i> Search by Surname / Name / Email</label>
            <input type="text" name="search" class="form-control" id="billsSearchInput"
                   placeholder="e.g. Santos, Maria, juan@email.com"
                   value="<?= htmlspecialchars($search) ?>"
                   style="background:rgba(255,255,255,0.04);">
        </div>
        <div class="form-group" style="margin:0;min-width:160px;">
            <label class="form-label">Status Filter</label>
            <select name="status" class="form-control" onchange="this.form.submit()">
                <option value="outstanding" <?= $statusFilter==='outstanding'?'selected':'' ?>>Outstanding Only</option>
                <option value="paid"        <?= $statusFilter==='paid'       ?'selected':'' ?>>Fully Paid</option>
                <option value="all"         <?= $statusFilter==='all'        ?'selected':'' ?>>All Bookings</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="height:42px;">
            <i class="fa-solid fa-filter"></i> Filter
        </button>
        <a href="?" class="btn btn-secondary" style="height:42px;display:flex;align-items:center;">Reset</a>
    </form>
</div>

<!-- Bills Table -->
<div class="card">
    <div class="table-wrapper">
        <table class="table" id="billsTable">
            <thead>
                <tr>
                    <th>Booking Ref</th>
                    <th>Customer</th>
                    <th>Package</th>
                    <th>Event Date</th>
                    <th>Payment Due</th>
                    <th>Total Amount</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bills)): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">
                    <i class="fa-solid fa-check-circle" style="font-size:2rem;color:#27ae60;margin-bottom:8px;display:block;"></i>
                    No records found.
                </td></tr>
                <?php endif; ?>
                <?php foreach ($bills as $b):
                    $balance    = round(max(0.0, (float)$b['total_amount'] - (float)$b['total_paid']), 2);
                    $isPaid     = $balance <= 0.005;
                    $pct        = (float)$b['total_amount'] > 0 ? min(100, round((float)$b['total_paid'] / (float)$b['total_amount'] * 100)) : 100;
                    $balColor   = $isPaid ? '#27ae60' : ($pct >= 50 ? '#f5a623' : '#e94560');
                    $dueDate    = getBookingPaymentDueDate($b);
                    $dueTs      = strtotime($dueDate);
                    $daysLeft   = (int)round(($dueTs - strtotime(date('Y-m-d'))) / 86400);
                ?>
                <tr class="bill-row" data-name="<?= strtolower(htmlspecialchars($b['customer_name'])) ?>">
                    <td>
                        <span style="color:var(--accent-teal);font-weight:700;font-size:0.88rem;">
                            <?= htmlspecialchars($b['booking_reference'] ?? 'BK-' . str_pad($b['booking_id'],8,'0',STR_PAD_LEFT)) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:700;"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);"><?= htmlspecialchars($b['customer_email']) ?></div>
                    </td>
                    <td style="font-size:0.88rem;"><?= htmlspecialchars($b['package_name']) ?></td>
                    <td style="font-size:0.85rem;color:var(--text-secondary);">
                        <?= $b['event_date'] ? date('M d, Y', strtotime($b['event_date'])) : '—' ?>
                    </td>
                    <td>
                        <?php if ($isPaid): ?>
                            <span class="badge badge-success" style="font-size:0.72rem;"><i class="fa-solid fa-check"></i> Paid</span>
                        <?php elseif ($daysLeft < 0): ?>
                            <div><strong style="color:#e94560;font-size:0.85rem;"><?= date('M d, Y', $dueTs) ?></strong></div>
                            <span class="badge badge-danger" style="font-size:0.72rem;padding:2px 6px;">Overdue (<?= abs($daysLeft) ?>d)</span>
                        <?php elseif ($daysLeft === 0): ?>
                            <div><strong style="color:#f5a623;font-size:0.85rem;"><?= date('M d, Y', $dueTs) ?></strong></div>
                            <span class="badge badge-warning" style="font-size:0.72rem;padding:2px 6px;">Due Today</span>
                        <?php elseif ($daysLeft <= 7): ?>
                            <div><strong style="color:#f5a623;font-size:0.85rem;"><?= date('M d, Y', $dueTs) ?></strong></div>
                            <span class="badge badge-warning" style="font-size:0.72rem;padding:2px 6px;"><?= $daysLeft ?> days left</span>
                        <?php else: ?>
                            <div style="font-size:0.85rem;color:var(--text-secondary);"><?= date('M d, Y', $dueTs) ?></div>
                            <span style="font-size:0.72rem;color:var(--text-muted);"><?= $daysLeft ?> days left</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:700;"><?= formatCurrency($b['total_amount']) ?></td>
                    <td style="color:#27ae60;font-weight:700;"><?= formatCurrency($b['total_paid']) ?></td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <span style="color:<?= $balColor ?>;font-weight:800;font-size:0.95rem;">
                                <?= $isPaid ? '<i class="fa-solid fa-circle-check"></i> PAID' : formatCurrency(abs($balance)) ?>
                            </span>
                            <div style="background:rgba(255,255,255,0.07);border-radius:4px;height:4px;width:90px;overflow:hidden;">
                                <div style="background:<?= $balColor ?>;height:100%;width:<?= $pct ?>%;border-radius:4px;transition:width 0.4s;"></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php
                        $bsColors = ['Confirmed'=>'#27ae60','Completed'=>'#4ecdc4','Cancelled'=>'#e94560','Pending'=>'#f5a623'];
                        $bc = $bsColors[$b['booking_status']] ?? '#aaa';
                        ?>
                        <span style="background:<?= $bc ?>20;color:<?= $bc ?>;border:1px solid <?= $bc ?>40;padding:3px 10px;border-radius:20px;font-size:0.78rem;font-weight:700;">
                            <?= htmlspecialchars($b['booking_status']) ?>
                        </span>
                        <?php if (!empty($b['approved_at'])): ?>
                            <div style="font-size:0.72rem;color:var(--text-muted);margin-top:5px;display:flex;align-items:center;gap:4px;" title="Approved on <?= date('M d, Y g:i A', strtotime($b['approved_at'])) ?>">
                                <i class="fa-solid fa-circle-check" style="color:#27ae60;font-size:0.75rem;"></i>
                                <span>Approved: <strong style="color:var(--text-secondary);"><?= date('M d, Y g:i A', strtotime($b['approved_at'])) ?></strong></span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                            <?php if ($b['booking_status'] === 'Pending'): ?>
                                <form method="POST" style="display:inline;margin:0;">
                                    <input type="hidden" name="action" value="approve_booking">
                                    <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm" style="white-space:nowrap;font-weight:700;padding:5px 10px;"
                                            onclick="return confirm('Approve booking <?= htmlspecialchars(addslashes(getBookingRef($b))) ?> for <?= htmlspecialchars(addslashes($b['customer_name'])) ?>?')"
                                            title="Approve booking to enable payment collection">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </button>
                                </form>
                            <?php elseif (!$isPaid && $b['booking_status'] !== 'Cancelled'): ?>
                                <button type="button" class="btn btn-success btn-sm"
                                        onclick="openPayModal(<?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['customer_name'])) ?>', <?= abs($balance) ?>, '<?= !empty($b['approved_at']) ? 'Approved on ' . date('M d, Y g:i A', strtotime($b['approved_at'])) : '' ?>')"
                                        title="Collect payment now">
                                    <i class="fa-solid fa-peso-sign"></i> Collect
                                </button>
                                <a href="<?= APP_URL ?>/staff/process_payment.php?id=<?= $b['booking_id'] ?>"
                                   class="btn btn-primary btn-sm" title="Detailed Payment & Receipts">
                                    <i class="fa-solid fa-file-invoice"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($b['latest_payment_id'])): ?>
                                <a href="<?= APP_URL ?>/receipt.php?id=<?= $b['latest_payment_id'] ?>" target="_blank"
                                   class="btn btn-secondary btn-sm" title="View Official Receipt" style="white-space:nowrap;">
                                    <i class="fa-solid fa-receipt"></i> Receipt
                                </a>
                            <?php endif; ?>
                            <?php if ($isPaid && empty($b['latest_payment_id'])): ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Fully Paid</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Quick Pay Modal -->
<div class="modal-overlay" id="quickPayModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-peso-sign"></i> Record Payment</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="mark_paid">
            <input type="hidden" name="booking_id" id="payBookingId">
            <div class="modal-body">
                <div id="payApprovedNotice" style="display:none;background:rgba(39,174,96,0.12);border:1px solid rgba(39,174,96,0.35);color:#27ae60;padding:8px 12px;border-radius:8px;font-size:0.82rem;font-weight:600;margin-bottom:14px;align-items:center;gap:8px;">
                    <i class="fa-solid fa-circle-check"></i>
                    <span id="payApprovedText"></span>
                </div>
                <p style="margin-bottom:16px;color:var(--text-secondary);font-size:0.9rem;">
                    Recording payment for: <strong id="payCustomerName" style="color:#fff;"></strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Amount to Pay (₱) <span style="color:var(--accent-red);">*</span></label>
                    <input type="number" name="amount" id="payAmount" class="form-control" step="any" min="0.01" required>
                    <div class="form-hint" id="payBalanceHint"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control" style="background:rgba(39,174,96,0.1);border-color:rgba(39,174,96,0.4);color:#27ae60;font-weight:700;">
                        <option value="cash" selected>💵 Cash (Walk-in Only)</option>
                    </select>
                    <div class="form-hint" style="color:var(--text-muted);font-size:0.78rem;margin-top:5px;">
                        <i class="fa-solid fa-person-walking"></i> Walk-in cash transactions only. Payments must be received in cash at the counter.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayModal(bookingId, customerName, balance, approvedText = '') {
    const balVal = parseFloat(balance);
    document.getElementById('payBookingId').value   = bookingId;
    document.getElementById('payCustomerName').textContent = customerName;
    document.getElementById('payAmount').value      = balVal.toFixed(2);
    document.getElementById('payAmount').max        = balVal.toFixed(2);
    document.getElementById('payBalanceHint').textContent  = 'Outstanding balance: ₱' + balVal.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

    const notice = document.getElementById('payApprovedNotice');
    const noticeTxt = document.getElementById('payApprovedText');
    if (notice && noticeTxt) {
        if (approvedText) {
            noticeTxt.textContent = approvedText;
            notice.style.display = 'flex';
        } else {
            notice.style.display = 'none';
        }
    }

    const m = document.getElementById('quickPayModal');
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeQuickPayModal() {
    const m = document.getElementById('quickPayModal');
    m.classList.remove('open');
    document.body.style.overflow = '';
}

document.querySelectorAll('#quickPayModal [data-modal-close], #quickPayModal .modal-close').forEach(btn => {
    btn.addEventListener('click', closeQuickPayModal);
});

document.getElementById('quickPayModal').addEventListener('click', function(e) {
    if (e.target === this) closeQuickPayModal();
});

document.getElementById('billsSearchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#billsTable .bill-row').forEach(row => {
        const name = row.dataset.name || '';
        const text = row.textContent.toLowerCase();
        row.style.display = (name.includes(q) || text.includes(q)) ? '' : 'none';
    });
});

window.addEventListener('DOMContentLoaded', function() {
    <?php 
    if (!empty($_GET['pay_booking_id'])) {
        $autoPayId = (int)$_GET['pay_booking_id'];
        $autoBk = null;
        foreach ($bills as $billItem) {
            if ((int)$billItem['booking_id'] === $autoPayId) {
                $autoBk = $billItem;
                break;
            }
        }
        if (!$autoBk) {
            $autoStmt = $db->prepare("SELECT b.booking_id, b.total_amount, b.approved_at, u.name AS customer_name,
                (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS total_paid
                FROM bookings b JOIN users u ON b.customer_id = u.user_id WHERE b.booking_id = ?");
            $autoStmt->execute([$autoPayId]);
            $autoBk = $autoStmt->fetch();
        }
        if ($autoBk) {
            $autoBal = round(max(0.0, (float)$autoBk['total_amount'] - (float)$autoBk['total_paid']), 2);
            $apprNotice = !empty($autoBk['approved_at']) ? 'Approved on ' . date('M d, Y g:i A', strtotime($autoBk['approved_at'])) : '';
            echo "openPayModal(" . $autoPayId . ", " . json_encode($autoBk['customer_name']) . ", " . $autoBal . ", " . json_encode($apprNotice) . ");\n";
        }
    }
    ?>
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
