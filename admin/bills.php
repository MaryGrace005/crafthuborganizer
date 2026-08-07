<?php
// ============================================================
//  Bills & Accounts Receivable — CraftHub Organizer
// ============================================================
$pageTitle = 'Bills & Accounts Receivable';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

// ── Quick-pay action ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $amount    = (float)($_POST['amount'] ?? 0);
    $method    = sanitize($_POST['payment_method'] ?? 'cash');
    $cashierId = $_SESSION['user_id'];

    if ($bookingId > 0 && $amount > 0) {
        $db->prepare("INSERT INTO payments (booking_id, cashier_id, amount_paid, payment_method, payment_date, notes)
                      VALUES (?, ?, ?, ?, NOW(), 'Quick pay from Bills page')")
           ->execute([$bookingId, $cashierId, $amount, $method]);
        logAudit($cashierId, 'PAYMENT', "Recorded payment of ₱{$amount} for booking #{$bookingId}", 'payments');
        setFlash('success', 'Payment of ' . formatCurrency($amount) . ' recorded successfully.');
    }
    redirect(APP_URL . '/admin/bills.php');
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
        b.status AS booking_status,
        b.created_at,
        u.name   AS customer_name,
        u.email  AS customer_email,
        u.contact_no,
        p.package_name,
        COALESCE(SUM(pay.amount_paid), 0) AS total_paid
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
    $balance = $bill['total_amount'] - $bill['total_paid'];
    $totalOutstanding += max(0, $balance);
    $totalCollected   += $bill['total_paid'];
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
                    <th>Total Amount</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bills)): ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">
                    <i class="fa-solid fa-check-circle" style="font-size:2rem;color:#27ae60;margin-bottom:8px;display:block;"></i>
                    No records found.
                </td></tr>
                <?php endif; ?>
                <?php foreach ($bills as $b):
                    $balance    = $b['total_amount'] - $b['total_paid'];
                    $isPaid     = $balance <= 0;
                    $pct        = $b['total_amount'] > 0 ? min(100, round($b['total_paid'] / $b['total_amount'] * 100)) : 100;
                    $balColor   = $isPaid ? '#27ae60' : ($pct >= 50 ? '#f5a623' : '#e94560');
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
                    </td>
                    <td>
                        <?php if (!$isPaid && $b['booking_status'] !== 'Cancelled'): ?>
                        <button class="btn btn-success btn-sm"
                                onclick="openPayModal(<?= $b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['customer_name'])) ?>', <?= abs($balance) ?>)">
                            <i class="fa-solid fa-peso-sign"></i> Pay
                        </button>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:0.8rem;">—</span>
                        <?php endif; ?>
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
                    <select name="payment_method" class="form-control">
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="check">Check</option>
                    </select>
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
// Open quick-pay modal
function openPayModal(bookingId, customerName, balance) {
    document.getElementById('payBookingId').value   = bookingId;
    document.getElementById('payCustomerName').textContent = customerName;
    document.getElementById('payAmount').value      = parseFloat(balance).toFixed(2);
    document.getElementById('payBalanceHint').textContent  = 'Outstanding balance: ₱' + parseFloat(balance).toLocaleString('en-PH', {minimumFractionDigits:2});
    document.getElementById('quickPayModal').classList.add('active');
}

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
