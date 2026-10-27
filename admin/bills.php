<?php
// ============================================================
//  Bills & Accounts Receivable — CraftHub Organizer
// ============================================================
$pageTitle = 'Bills & Accounts Receivable';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();
ensureSecurityDepositColumns($db);
ensureExcessGuestColumns($db);

// ── Quick-pay action & Deposit action ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Handle deposit return / forfeit ──────────────────────
    if ($action === 'update_deposit') {
        $bookingId     = (int)($_POST['booking_id'] ?? 0);
        $depositAction = $_POST['deposit_action'] ?? '';
        $depositNote   = sanitize($_POST['deposit_note'] ?? '');
        $adminId       = $_SESSION['user_id'];

        if ($bookingId > 0 && in_array($depositAction, ['returned', 'forfeited'])) {
            $upd = $db->prepare("UPDATE bookings SET deposit_status = ?, deposit_returned_at = NOW(), deposit_note = ? WHERE booking_id = ?");
            $upd->execute([$depositAction, $depositNote ?: null, $bookingId]);
            $label = $depositAction === 'returned' ? 'returned to customer' : 'forfeited (damage)';
            logAudit($adminId, 'DEPOSIT_' . strtoupper($depositAction), "Security deposit {$label} for booking #{$bookingId}. Note: {$depositNote}", 'bookings');
            setFlash('success', 'Security deposit has been marked as <strong>' . ucfirst($depositAction) . '</strong> successfully.');
        } else {
            setFlash('error', 'Invalid deposit action.');
        }
        redirect(APP_URL . '/admin/bills.php');
    }

    if ($action === 'mark_paid') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $amount    = (float)($_POST['amount'] ?? 0);
    $method    = sanitize($_POST['payment_method'] ?? 'cash');
    $cashierId = $_SESSION['user_id'];

    if ($bookingId > 0 && $amount > 0) {
        // Real-time check: Do not record payment if booking is pending
        $chkStmt = $db->prepare("SELECT status, total_amount, (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = bookings.booking_id) AS paid_so_far FROM bookings WHERE booking_id = ?");
        $chkStmt->execute([$bookingId]);
        $currBooking = $chkStmt->fetch();

        if (!$currBooking) {
            setFlash('error', 'Booking not found.');
            redirect(APP_URL . '/admin/bills.php');
        }

        if (strtolower($currBooking['status']) === 'cancelled') {
            setFlash('error', 'Cannot collect payment: This booking is cancelled.');
            redirect(APP_URL . '/admin/bills.php');
        }

        $wasPending = strtolower($currBooking['status']) === 'pending';

        $remainingBalance = max(0.0, (float)$currBooking['total_amount'] - (float)$currBooking['paid_so_far']);
        if ($remainingBalance <= 0) {
            setFlash('info', 'This booking is already fully paid.');
            redirect(APP_URL . '/admin/bills.php');
        }
        if ($amount > $remainingBalance) {
            $amount = $remainingBalance;
        }

        $orNumber = 'OR-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $ins = $db->prepare("INSERT INTO payments (booking_id, cashier_id, amount_paid, payment_method, or_number, payment_date, notes)
                      VALUES (?, ?, ?, ?, ?, NOW(), 'Quick pay from Admin Bills page')");
        $ins->execute([$bookingId, $cashierId, $amount, $method, $orNumber]);
        $newPayId = (int)$db->lastInsertId();

        try {
            $upd = $db->prepare("UPDATE bookings SET amount_paid = COALESCE(amount_paid, 0) + ? WHERE booking_id = ?");
            $upd->execute([$amount, $bookingId]);
        } catch (PDOException $e) {}

        if ($wasPending) {
            ensureApprovalColumns($db);
            $db->prepare("UPDATE bookings SET status = 'Confirmed', approved_at = NOW(), approved_by = ? WHERE booking_id = ?")
               ->execute([$cashierId, $bookingId]);
        }

        updateBookingPaymentStatus($bookingId);

        logAudit($cashierId, 'PAYMENT', "Recorded payment of ₱{$amount} for booking #{$bookingId}", 'payments');
        $apprMsg = $wasPending ? ' Booking has been officially <strong>Approved &amp; Confirmed</strong>.' : '';
        setFlash('success', 'Payment of ' . formatCurrency($amount) . ' recorded successfully!' . $apprMsg . ' <a href="' . APP_URL . '/receipt.php?id=' . $newPayId . '" target="_blank" style="color:#fff;text-decoration:underline;margin-left:8px;font-weight:700;"><i class="fa-solid fa-receipt"></i> View Receipt (' . $orNumber . ')</a>');
    }
    redirect(APP_URL . '/admin/bills.php');
    } // end mark_paid
} // end POST

// ── Filters ────────────────────────────────────────────────
$search     = sanitize($_GET['search'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? 'outstanding');

// ── Query outstanding balances ─────────────────────────────
$sql = "
    SELECT
        b.booking_id,
        b.booking_reference,
        b.total_amount,
        COALESCE(b.guest_count, 1)         AS guest_count,
        COALESCE(b.excess_guests, 0)        AS excess_guests,
        COALESCE(b.excess_guest_fee, 0)     AS excess_guest_fee,
        COALESCE(b.per_guest_fee, 75.00)    AS per_guest_fee,
        COALESCE(b.security_deposit, 0)   AS security_deposit,
        COALESCE(b.deposit_status, 'held') AS deposit_status,
        b.deposit_returned_at,
        b.deposit_note,
        b.event_date,
        b.payment_due_date,
        b.status AS booking_status,
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
<div class="page-header" style="background:linear-gradient(135deg,rgba(233,69,96,0.08),rgba(245,166,35,0.05));border:1px solid rgba(233,69,96,0.15);border-radius:20px;padding:28px 32px;margin-bottom:24px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#e94560,#f5a623,#4ecdc4);"></div>
    <div>
        <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(233,69,96,0.1);border:1px solid rgba(233,69,96,0.3);color:#e94560;padding:5px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">
            <i class="fa-solid fa-file-invoice-dollar"></i> Accounts Receivable
        </div>
        <h1 style="font-family:'Outfit',sans-serif;font-size:1.9rem;font-weight:800;margin-bottom:4px;">Bills &amp; Outstanding Balances</h1>
        <p style="color:var(--text-secondary);font-size:0.92rem;">Track unpaid balances, record payments, and manage accounts receivable.</p>
    </div>
    <div style="display:flex;gap:24px;flex-shrink:0;">
        <div style="text-align:center;">
            <div style="font-family:'Outfit',sans-serif;font-size:1.5rem;font-weight:800;color:#e94560;"><?= formatCurrency($totalOutstanding) ?></div>
            <div style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Outstanding</div>
        </div>
        <div style="text-align:center;">
            <div style="font-family:'Outfit',sans-serif;font-size:1.5rem;font-weight:800;color:#27ae60;"><?= formatCurrency($totalCollected) ?></div>
            <div style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Collected</div>
        </div>
    </div>
</div>

<?php displayFlash(); ?>

<!-- Search + Filter Bar -->
<div class="card" style="margin-bottom:20px;">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:4px 0;">
        <div class="form-group" style="margin:0;flex:1;min-width:220px;">
            <label class="form-label"><i class="fa-solid fa-magnifying-glass"></i> Search by Name / Surname / Email</label>
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
                    <th>Sec. Deposit</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bills)): ?>
                <tr><td colspan="11" style="text-align:center;padding:40px;color:var(--text-muted);">
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
                    <td style="font-size:0.88rem;">
                        <div style="font-weight:600;"><?= htmlspecialchars($b['package_name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
                            <i class="fa-solid fa-users"></i> <?= (int)($b['guest_count'] ?? 1) ?> guests
                            <?php if ((float)($b['excess_guest_fee'] ?? 0) > 0): ?>
                                <span style="color:#f5a623;font-weight:700;">(+<?= (int)$b['excess_guests'] ?> extra)</span>
                            <?php endif; ?>
                        </div>
                    </td>
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
                    <td>
                        <div style="font-weight:700;"><?= formatCurrency($b['total_amount']) ?></div>
                        <?php if ((float)($b['excess_guest_fee'] ?? 0) > 0): ?>
                            <div style="font-size:0.7rem;color:#f5a623;margin-top:2px;" title="<?= (int)$b['excess_guests'] ?> excess guests @ ₱<?= number_format((float)($b['per_guest_fee'] ?? 75), 2) ?>">
                                <i class="fa-solid fa-user-plus"></i> +<?= formatCurrency($b['excess_guest_fee']) ?> excess
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php
                    $dep       = (float)$b['security_deposit'];
                    $depStatus = $b['deposit_status'] ?? 'held';
                    $depColors = ['held'=>'#f5a623','returned'=>'#27ae60','forfeited'=>'#e94560'];
                    $depLabels = ['held'=>'Held','returned'=>'Returned','forfeited'=>'Forfeited'];
                    $depIcons  = ['held'=>'fa-lock','returned'=>'fa-rotate-left','forfeited'=>'fa-ban'];
                    $dc = $depColors[$depStatus] ?? '#aaa';
                    $dl = $depLabels[$depStatus] ?? ucfirst($depStatus);
                    $di = $depIcons[$depStatus]  ?? 'fa-circle';
                    ?>
                    <td>
                        <?php if ($dep > 0): ?>
                            <div style="font-weight:700;color:#a78bfa;"><?= formatCurrency($dep) ?></div>
                            <span style="display:inline-flex;align-items:center;gap:4px;background:<?= $dc ?>18;color:<?= $dc ?>;border:1px solid <?= $dc ?>44;padding:2px 8px;border-radius:20px;font-size:0.7rem;font-weight:700;margin-top:4px;">
                                <i class="fa-solid <?= $di ?>"></i> <?= $dl ?>
                            </span>
                            <?php if (!empty($b['deposit_returned_at'])): ?>
                                <div style="font-size:0.7rem;color:var(--text-muted);margin-top:3px;"><?= date('M d, Y', strtotime($b['deposit_returned_at'])) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:0.82rem;">—</span>
                        <?php endif; ?>
                    </td>
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
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                            <?php if ($b['booking_status'] === 'Pending'): ?>
                                <button type="button" class="btn btn-success btn-sm"
                                        onclick="openPayModal(<?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['customer_name'])) ?>', <?= abs($balance) ?>)"
                                        title="Collect payment and approve booking" style="white-space:nowrap;">
                                    <i class="fa-solid fa-peso-sign"></i> Collect &amp; Approve
                                </button>
                            <?php elseif (!$isPaid && $b['booking_status'] !== 'Cancelled'): ?>
                                <button type="button" class="btn btn-success btn-sm"
                                        onclick="openPayModal(<?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['customer_name'])) ?>', <?= abs($balance) ?>)"
                                        title="Record payment for this bill">
                                    <i class="fa-solid fa-peso-sign"></i> Pay
                                </button>
                            <?php endif; ?>
                            <?php if (!empty($b['latest_payment_id'])): ?>
                                <a href="<?= APP_URL ?>/receipt.php?id=<?= $b['latest_payment_id'] ?>" target="_blank"
                                   class="btn btn-secondary btn-sm" title="View Official Payment Receipt" style="white-space:nowrap;">
                                    <i class="fa-solid fa-receipt"></i> Receipt
                                </a>
                            <?php endif; ?>
                            <?php if ($isPaid && empty($b['latest_payment_id'])): ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Paid</span>
                            <?php endif; ?>
                            <?php
                            $dep2   = (float)$b['security_deposit'];
                            $depSt2 = $b['deposit_status'] ?? 'held';
                            if ($dep2 > 0 && $depSt2 === 'held' && $b['booking_status'] !== 'Cancelled'):
                            ?>
                                <button type="button" class="btn btn-sm" style="background:rgba(167,139,250,0.15);color:#a78bfa;border:1px solid rgba(167,139,250,0.4);white-space:nowrap;"
                                        onclick="openDepositModal(<?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['customer_name'])) ?>', <?= $dep2 ?>)"
                                        title="Process security deposit return (no damage) or forfeiture">
                                    <i class="fa-solid fa-shield-halved"></i> Return/Forfeit Deposit
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Security Deposit Modal -->
<div class="modal-overlay" id="depositModal">
    <div class="modal" style="max-width:460px;">
        <div class="modal-header" style="background:linear-gradient(135deg,rgba(167,139,250,0.15),rgba(168,85,247,0.08));">
            <h2 class="modal-title"><i class="fa-solid fa-shield-halved" style="color:#a78bfa;"></i> Security Deposit</h2>
            <button class="modal-close" onclick="closeDepositModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_deposit">
            <input type="hidden" name="booking_id" id="depBookingId">
            <div class="modal-body">
                <div style="background:rgba(167,139,250,0.08);border:1px solid rgba(167,139,250,0.25);border-radius:12px;padding:14px 18px;margin-bottom:18px;">
                    <div style="font-size:0.82rem;color:var(--text-muted);margin-bottom:4px;">Customer</div>
                    <div style="font-weight:700;color:#fff;" id="depCustomerName"></div>
                    <div style="margin-top:10px;display:flex;align-items:center;gap:10px;">
                        <span style="font-size:0.82rem;color:var(--text-muted);">Security Deposit Amount:</span>
                        <span style="font-family:'Outfit',sans-serif;font-size:1.3rem;font-weight:800;color:#a78bfa;" id="depAmount"></span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Deposit Decision <span style="color:var(--accent-red);">*</span></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <label style="display:flex;align-items:flex-start;gap:10px;background:rgba(39,174,96,0.08);border:2px solid rgba(39,174,96,0.25);border-radius:12px;padding:14px;cursor:pointer;transition:border-color 0.2s;" id="depReturnCard">
                            <input type="radio" name="deposit_action" value="returned" required onchange="updateDepCard()" style="margin-top:2px;accent-color:#27ae60;">
                            <div>
                                <div style="font-weight:700;color:#27ae60;font-size:0.9rem;"><i class="fa-solid fa-rotate-left"></i> Return Deposit</div>
                                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:3px;">No damage &mdash; full deposit returned to customer</div>
                            </div>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:10px;background:rgba(233,69,96,0.08);border:2px solid rgba(233,69,96,0.25);border-radius:12px;padding:14px;cursor:pointer;transition:border-color 0.2s;" id="depForfeitCard">
                            <input type="radio" name="deposit_action" value="forfeited" onchange="updateDepCard()" style="margin-top:2px;accent-color:#e94560;">
                            <div>
                                <div style="font-weight:700;color:#e94560;font-size:0.9rem;"><i class="fa-solid fa-ban"></i> Forfeit Deposit</div>
                                <div style="font-size:0.75rem;color:var(--text-muted);margin-top:3px;">Damage occurred &mdash; deposit is forfeited</div>
                            </div>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="adm_deposit_note">Note / Reason <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                    <textarea id="adm_deposit_note" name="deposit_note" class="form-control" rows="2"
                              placeholder="e.g. No damage found, full deposit returned to customer."></textarea>
                </div>
                <div style="background:rgba(245,166,35,0.08);border:1px solid rgba(245,166,35,0.25);border-radius:8px;padding:10px 14px;font-size:0.8rem;color:var(--text-secondary);">
                    <i class="fa-solid fa-circle-info" style="color:#f5a623;"></i>
                    This action is <strong>permanent</strong> and will be logged in the audit trail. Verify the venue condition before proceeding.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDepositModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="depSubmitBtn"><i class="fa-solid fa-check"></i> Confirm</button>
            </div>
        </form>
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
                <p style="margin-bottom:16px;color:var(--text-secondary);font-size:0.9rem;">
                    Recording payment for: <strong id="payCustomerName" style="color:#fff;"></strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Amount to Pay (₱) <span style="color:var(--accent-red);">*</span></label>
                    <input type="number" name="amount" id="payAmount" class="form-control" step="0.01" min="1" required>
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
// Deposit Modal
function openDepositModal(bookingId, customerName, amount) {
    document.getElementById('depBookingId').value = bookingId;
    document.getElementById('depCustomerName').textContent = customerName;
    document.getElementById('depAmount').textContent = '\u20b1' + parseFloat(amount).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.querySelectorAll('#depositModal input[name="deposit_action"]').forEach(r => r.checked = false);
    document.getElementById('adm_deposit_note').value = '';
    document.getElementById('depositModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeDepositModal() {
    document.getElementById('depositModal').classList.remove('open');
    document.body.style.overflow = '';
}
function updateDepCard() {
    const ret  = document.querySelector('input[name="deposit_action"][value="returned"]').checked;
    const forf = document.querySelector('input[name="deposit_action"][value="forfeited"]').checked;
    document.getElementById('depReturnCard').style.borderColor  = ret  ? '#27ae60' : 'rgba(39,174,96,0.25)';
    document.getElementById('depForfeitCard').style.borderColor = forf ? '#e94560' : 'rgba(233,69,96,0.25)';
    const btn = document.getElementById('depSubmitBtn');
    if (ret)  { btn.style.background='#27ae60'; btn.innerHTML='<i class="fa-solid fa-rotate-left"></i> Return Deposit'; }
    else if (forf) { btn.style.background='#e94560'; btn.innerHTML='<i class="fa-solid fa-ban"></i> Forfeit Deposit'; }
}
document.getElementById('depositModal').addEventListener('click', function(e) {
    if (e.target === this) closeDepositModal();
});

// Quick-pay modal
function openPayModal(bookingId, customerName, balance) {
    document.getElementById('payBookingId').value   = bookingId;
    document.getElementById('payCustomerName').textContent = customerName;
    document.getElementById('payAmount').value      = parseFloat(balance).toFixed(2);
    document.getElementById('payBalanceHint').textContent  = 'Outstanding balance: \u20b1' + parseFloat(balance).toLocaleString('en-PH', {minimumFractionDigits:2});
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

// Live surname/name search filter on the table
document.getElementById('billsSearchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#billsTable .bill-row').forEach(row => {
        const name = row.dataset.name || '';
        const text = row.textContent.toLowerCase();
        row.style.display = (name.includes(q) || text.includes(q)) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
