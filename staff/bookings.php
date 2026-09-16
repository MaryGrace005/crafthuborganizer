<?php
// ============================================================
//  Customer Bookings & Approvals — CraftHub Organizer (Cashier/Staff)
// ============================================================
$pageTitle = 'Customer Bookings';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);

$db        = getDB();
ensureApprovalColumns($db);
$cashierId = $_SESSION['user_id'] ?? 0;

// ── Handle Actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'approve' && $id > 0) {
        $chk = $db->prepare("SELECT b.*, u.name AS customer_name FROM bookings b JOIN users u ON b.customer_id = u.user_id WHERE b.booking_id = ?");
        $chk->execute([$id]);
        $booking = $chk->fetch();

        if (!$booking) {
            setFlash('error', 'Booking not found.');
            redirect(APP_URL . '/staff/bookings.php');
        } elseif (strtolower($booking['status']) === 'cancelled') {
            setFlash('error', 'Cannot approve a cancelled booking.');
            redirect(APP_URL . '/staff/bookings.php');
        } else {
            $ref = getBookingRef($booking);
            ensureApprovalColumns($db);
            $now = date('Y-m-d H:i:s');
            $stmt = $db->prepare("UPDATE bookings SET status = 'Confirmed', approved_at = ?, approved_by = ? WHERE booking_id = ?");
            $stmt->execute([$now, $cashierId, $id]);
            updateBookingPaymentStatus($id);

            $timeFormatted = date('M d, Y g:i A', strtotime($now));
            logAudit($cashierId, 'APPROVE_BOOKING', "Cashier approved booking #{$id} ({$ref}) for {$booking['customer_name']} on {$timeFormatted}", 'bookings');
            setFlash('success', "✓ Booking <strong>{$ref}</strong> approved on <strong>{$timeFormatted}</strong>! You can now collect payment.");
            redirect(APP_URL . '/staff/bookings.php');
        }
    }

    if ($action === 'cancel' && $id > 0) {
        $chk = $db->prepare("SELECT b.*, u.name AS customer_name FROM bookings b JOIN users u ON b.customer_id = u.user_id WHERE b.booking_id = ?");
        $chk->execute([$id]);
        $booking = $chk->fetch();

        if ($booking) {
            $ref = getBookingRef($booking);
            $reason = sanitize($_POST['cancel_reason'] ?? 'Cancelled by Cashier');
            $stmt = $db->prepare("UPDATE bookings SET status = 'Cancelled' WHERE booking_id = ?");
            $stmt->execute([$id]);

            logAudit($cashierId, 'CANCEL_BOOKING', "Cashier cancelled booking #{$id} ({$ref}). Reason: {$reason}", 'bookings');
            setFlash('info', "Booking <strong>{$ref}</strong> has been cancelled.");
        } else {
            setFlash('error', 'Booking not found.');
        }
        redirect(APP_URL . '/staff/bookings.php');
    }
}

// ── Filters & Queries ─────────────────────────────────────────
$statusFilter = sanitize($_GET['status'] ?? 'all');
$allowedFilters = ['all', 'pending', 'confirmed', 'completed', 'cancelled'];
if (!in_array($statusFilter, $allowedFilters)) $statusFilter = 'all';

// Pending count for tab badge
$pendingCount = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE status = 'Pending'")->fetchColumn();

$sql = "
    SELECT b.*, b.booking_id AS id,
           u.name  AS customer_name, u.email AS customer_email, u.contact_no AS customer_phone,
           p.package_name,
           v.venue_name,
           COALESCE((SELECT SUM(py.amount_paid) FROM payments py WHERE py.booking_id = b.booking_id), 0) AS real_amount_paid,
           (SELECT MAX(py.payment_id) FROM payments py WHERE py.booking_id = b.booking_id) AS latest_payment_id
    FROM bookings b
    JOIN users    u ON b.customer_id = u.user_id
    JOIN packages p ON b.package_id  = p.package_id
    LEFT JOIN venues v ON b.venue_id = v.venue_id
";
$params = [];

if ($statusFilter !== 'all') {
    $sql .= " WHERE b.status = ?";
    $params[] = ucfirst($statusFilter);
}

$sql .= " ORDER BY (b.status = 'Pending') DESC, b.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- Page Header -->
<div class="page-header" style="background:linear-gradient(135deg,rgba(245,166,35,0.08),rgba(78,205,196,0.05));border:1px solid rgba(245,166,35,0.2);border-radius:20px;padding:26px 30px;margin-bottom:24px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#f5a623,#4ecdc4,#27ae60);"></div>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
        <div>
            <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(245,166,35,0.12);border:1px solid rgba(245,166,35,0.3);color:#f5a623;padding:5px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;">
                <i class="fa-solid fa-stamp"></i> Cashier Booking Authorization
            </div>
            <h1 style="font-family:'Outfit',sans-serif;font-size:1.85rem;font-weight:800;margin-bottom:4px;">Customer Bookings &amp; Approvals</h1>
            <p style="color:var(--text-secondary);font-size:0.9rem;">Review incoming customer bookings, approve events, and process walk-in reservations.</p>
        </div>
        <div style="display:flex;gap:14px;">
            <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:12px 20px;text-align:center;">
                <div style="font-size:1.4rem;font-weight:800;color:<?= $pendingCount > 0 ? '#f5a623' : '#27ae60' ?>;font-family:'Outfit',sans-serif;">
                    <?= $pendingCount ?>
                </div>
                <div style="font-size:0.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Awaiting Approval</div>
            </div>
        </div>
    </div>
</div>

<?php displayFlash(); ?>

<!-- Status Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php
    $tabs = [
        'all'       => ['label' => 'All Bookings',       'icon' => 'fa-list'],
        'pending'   => ['label' => 'Pending Approval',   'icon' => 'fa-clock', 'count' => $pendingCount],
        'confirmed' => ['label' => 'Confirmed',          'icon' => 'fa-circle-check'],
        'completed' => ['label' => 'Completed',          'icon' => 'fa-flag-checkered'],
        'cancelled' => ['label' => 'Cancelled',          'icon' => 'fa-ban'],
    ];
    foreach ($tabs as $st => $tab):
        $active = $statusFilter === $st;
    ?>
        <a href="?status=<?= $st ?>" class="filter-tab <?= $active ? 'filter-tab--active' : '' ?>">
            <i class="fa-solid <?= $tab['icon'] ?>"></i>
            <?= $tab['label'] ?>
            <?php if (!empty($tab['count']) && $tab['count'] > 0): ?>
                <span style="background:#f5a623;color:#0f0f1a;border-radius:10px;padding:1px 7px;font-size:0.72rem;font-weight:800;margin-left:4px;">
                    <?= $tab['count'] ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<style>
.filter-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 30px;
    font-family: 'Outfit', sans-serif;
    font-size: 0.85rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-decoration: none;
    color: var(--text-secondary);
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.09);
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    white-space: nowrap;
}
.filter-tab:hover {
    color: var(--text-primary);
    background: rgba(255,255,255,0.09);
    border-color: rgba(255,255,255,0.18);
    transform: translateY(-2px);
}
.filter-tab--active {
    background: linear-gradient(135deg, #f5a623 0%, #e67e22 100%);
    color: #fff;
    border-color: rgba(245,166,35,0.4);
    box-shadow: 0 4px 18px rgba(245,166,35,0.35);
}

.ref-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    background: rgba(78,205,196,0.1);
    border: 1px solid rgba(78,205,196,0.25);
    border-radius: 8px;
    font-family: 'Outfit', sans-serif;
    font-size: 0.82rem;
    font-weight: 800;
    color: var(--accent-teal);
    letter-spacing: 0.02em;
    white-space: nowrap;
}

.amount-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px 5px 5px;
    background: linear-gradient(135deg, rgba(245,166,35,0.12) 0%, rgba(230,126,34,0.07) 100%);
    border: 1px solid rgba(245,166,35,0.28);
    border-radius: 30px;
    font-family: 'Outfit', sans-serif;
    white-space: nowrap;
}
.amount-chip .cur {
    width: 20px; height: 20px;
    background: rgba(245,166,35,0.2);
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.72rem; font-weight: 800;
    color: var(--accent-gold);
}
.amount-chip .amt {
    font-size: 0.9rem;
    font-weight: 800;
    color: #fff;
}

.btn-approve-bk {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    font-size: 0.82rem;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    border-radius: 10px;
    background: linear-gradient(135deg, #27ae60 0%, #219653 100%);
    border: 1px solid rgba(39,174,96,0.4);
    color: #fff;
    cursor: pointer;
    transition: all 0.25s ease;
    box-shadow: 0 3px 12px rgba(39,174,96,0.3);
    white-space: nowrap;
}
.btn-approve-bk:hover {
    background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(39,174,96,0.45);
}
</style>

<!-- Bookings Card -->
<div class="card">
    <div class="search-bar" style="margin-bottom:18px;">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" id="cashierBookingSearch" class="form-control"
                   placeholder="Search customer name, email, package, or booking ref..."
                   style="font-size:0.9rem;">
        </div>
        <span id="bookingSearchCount" style="margin-left:12px;font-size:0.82rem;color:var(--text-muted);"></span>
    </div>

    <div class="table-wrapper">
        <table class="table" id="cashierBookingsTable">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Package</th>
                    <th>Venue</th>
                    <th>Event Date</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookings)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">
                        <i class="fa-solid fa-calendar-xmark" style="font-size:2rem;margin-bottom:8px;display:block;color:rgba(255,255,255,0.2);"></i>
                        No bookings found under this filter.
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($bookings as $b):
                    $isPending = strtolower($b['status']) === 'pending';
                    $realPaid  = round((float)($b['real_amount_paid'] ?? 0), 2);
                    $realTotal = round((float)($b['total_amount'] ?? 0), 2);
                    $balance   = round(max(0.0, $realTotal - $realPaid), 2);
                    $isPaid    = $balance <= 0.005 && $realTotal > 0;
                    $ref       = getBookingRef($b);
                ?>
                <tr class="booking-row <?= $isPending ? 'pending-highlight' : '' ?>"
                    data-name="<?= strtolower(htmlspecialchars($b['customer_name'])) ?>"
                    data-ref="<?= strtolower(htmlspecialchars($ref)) ?>"
                    data-email="<?= strtolower(htmlspecialchars($b['customer_email'])) ?>"
                    data-pkg="<?= strtolower(htmlspecialchars($b['package_name'])) ?>"
                    style="<?= $isPending ? 'background:rgba(245,166,35,0.04);' : '' ?>">
                    <td>
                        <span class="ref-chip">
                            <i class="fa-solid fa-hashtag" style="font-size:0.7rem;opacity:0.7;"></i>
                            <?= htmlspecialchars($ref) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:700;color:#fff;"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div style="font-size:0.76rem;color:var(--text-secondary);"><?= htmlspecialchars($b['customer_email']) ?></div>
                        <?php if (!empty($b['customer_phone'])): ?>
                            <div style="font-size:0.72rem;color:var(--text-muted);"><i class="fa-solid fa-phone" style="font-size:0.65rem;"></i> <?= htmlspecialchars($b['customer_phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($b['package_name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);"><i class="fa-solid fa-users"></i> <?= (int)($b['guest_count'] ?? 1) ?> guests</div>
                    </td>
                    <td><?= htmlspecialchars($b['venue_name'] ?? 'No Venue') ?></td>
                    <td style="white-space:nowrap;">
                        <div style="font-weight:600;"><?= formatDate($b['event_date']) ?></div>
                        <small style="color:var(--text-muted);"><?= date('g:i A', strtotime($b['event_time'] ?? '09:00:00')) ?></small>
                        <br><small style="color:var(--accent-gold);"><i class="fa-solid fa-clock"></i> Due: <?= date('M d, Y', strtotime(getBookingPaymentDueDate($b))) ?></small>
                    </td>
                    <td>
                        <div class="amount-chip">
                            <span class="cur">₱</span>
                            <span class="amt"><?= number_format($realTotal, 0, '.', ',') ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($isPending): ?>
                            <span class="badge badge-warning" style="font-weight:700;box-shadow:0 0 10px rgba(245,166,35,0.3);">
                                <i class="fa-solid fa-clock"></i> Awaiting Approval
                            </span>
                        <?php else: ?>
                            <?= statusBadge($b['status']) ?>
                            <?php if (!empty($b['approved_at'])): ?>
                                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;display:flex;align-items:center;gap:4px;" title="Approved on <?= date('M d, Y g:i A', strtotime($b['approved_at'])) ?>">
                                    <i class="fa-solid fa-circle-check" style="color:#27ae60;font-size:0.75rem;"></i>
                                    <span>Approved: <strong style="color:var(--text-secondary);"><?= date('M d, Y g:i A', strtotime($b['approved_at'])) ?></strong></span>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isPaid): ?>
                            <span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Paid</span>
                        <?php elseif ($realPaid > 0): ?>
                            <span class="badge badge-warning" title="<?= formatCurrency($realPaid) ?> paid"><i class="fa-solid fa-circle-half-stroke"></i> Partial</span>
                            <div style="font-size:0.72rem;color:var(--accent-red);margin-top:2px;"><?= formatCurrency($balance) ?> due</div>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fa-solid fa-circle-xmark"></i> Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap;">
                            <?php if ($isPending): ?>
                                <!-- Approve Button -->
                                <button type="button" class="btn-approve-bk"
                                        onclick="openApproveModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($ref)) ?>', '<?= htmlspecialchars(addslashes($b['customer_name'])) ?>', '<?= htmlspecialchars(addslashes($b['package_name'])) ?>', '<?= formatCurrency($realTotal) ?>', '<?= formatDate($b['event_date']) ?>')"
                                        title="Approve and confirm this booking">
                                    <i class="fa-solid fa-check"></i> Approve
                                </button>
                                <!-- Cancel/Decline Button -->
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="openCancelModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($ref)) ?>', '<?= htmlspecialchars(addslashes($b['customer_name'])) ?>')"
                                        title="Decline or cancel this booking" style="color:#e94560;border-color:rgba(233,69,96,0.3);">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            <?php else: ?>
                                <!-- Confirmed / Paid Actions -->
                                <a href="<?= APP_URL ?>/staff/bills.php?search=<?= urlencode($ref) ?>" class="btn btn-primary btn-sm" title="View Bill &amp; Collect Payment" style="white-space:nowrap;">
                                    <i class="fa-solid fa-file-invoice-dollar"></i> Bill
                                </a>
                                <a href="<?= APP_URL ?>/booking_images.php?booking_id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm" title="View &amp; Upload Event Photos">
                                    <i class="fa-solid fa-camera"></i>
                                </a>
                                <?php if (!empty($b['latest_payment_id'])): ?>
                                    <a href="<?= APP_URL ?>/receipt.php?id=<?= $b['latest_payment_id'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="View Receipt">
                                        <i class="fa-solid fa-receipt"></i>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ APPROVE BOOKING MODAL ══ -->
<div class="modal-overlay" id="approveBookingModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <h2 class="modal-title" style="color:#27ae60;"><i class="fa-solid fa-circle-check"></i> Approve Customer Booking</h2>
            <button class="modal-close" onclick="closeApproveModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id" id="approveBookingId">
            <div class="modal-body">
                <p style="margin-bottom:16px;color:var(--text-secondary);font-size:0.9rem;">
                    As Cashier, you are confirming this customer booking. Once approved, the booking status becomes <strong>Confirmed</strong> and payment can be received.
                </p>

                <div style="background:rgba(39,174,96,0.08);border:1px solid rgba(39,174,96,0.25);border-radius:12px;padding:16px;margin-bottom:18px;">
                    <div style="display:grid;gap:8px;font-size:0.9rem;">
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--text-secondary);">Booking Ref:</span>
                            <strong id="modalApproveRef" style="color:var(--accent-teal);"></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--text-secondary);">Customer:</span>
                            <strong id="modalApproveCustomer" style="color:#fff;"></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--text-secondary);">Package:</span>
                            <span id="modalApprovePackage" style="color:#fff;"></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--text-secondary);">Event Date:</span>
                            <span id="modalApproveDate" style="color:#fff;"></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;border-top:1px solid rgba(255,255,255,0.08);padding-top:8px;margin-top:4px;">
                            <span style="color:var(--text-secondary);">Total Price:</span>
                            <strong id="modalApproveTotal" style="color:var(--accent-gold);font-size:1.05rem;"></strong>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fa-solid fa-check"></i> Confirm Approval</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ DECLINE / CANCEL MODAL ══ -->
<div class="modal-overlay" id="cancelBookingModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <h2 class="modal-title" style="color:#e94560;"><i class="fa-solid fa-ban"></i> Decline Booking</h2>
            <button class="modal-close" onclick="closeCancelModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" id="cancelBookingId">
            <div class="modal-body">
                <p style="margin-bottom:14px;color:var(--text-secondary);font-size:0.9rem;">
                    Are you sure you want to decline / cancel booking <strong id="modalCancelRef" style="color:#fff;"></strong> for <strong id="modalCancelCustomer" style="color:#fff;"></strong>?
                </p>
                <div class="form-group">
                    <label class="form-label">Reason for Cancellation</label>
                    <input type="text" name="cancel_reason" class="form-control" placeholder="e.g. Schedule unavailable, Customer requested cancellation">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">Go Back</button>
                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-ban"></i> Cancel Booking</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(id, ref, customer, pkg, total, date) {
    document.getElementById('approveBookingId').value = id;
    document.getElementById('modalApproveRef').textContent = ref;
    document.getElementById('modalApproveCustomer').textContent = customer;
    document.getElementById('modalApprovePackage').textContent = pkg;
    document.getElementById('modalApproveTotal').textContent = total;
    document.getElementById('modalApproveDate').textContent = date;
    const m = document.getElementById('approveBookingModal');
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeApproveModal() {
    const m = document.getElementById('approveBookingModal');
    m.classList.remove('open');
    document.body.style.overflow = '';
}

function openCancelModal(id, ref, customer) {
    document.getElementById('cancelBookingId').value = id;
    document.getElementById('modalCancelRef').textContent = ref;
    document.getElementById('modalCancelCustomer').textContent = customer;
    const m = document.getElementById('cancelBookingModal');
    m.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeCancelModal() {
    const m = document.getElementById('cancelBookingModal');
    m.classList.remove('open');
    document.body.style.overflow = '';
}

// Client-side search
(function() {
    const input   = document.getElementById('cashierBookingSearch');
    const table   = document.getElementById('cashierBookingsTable');
    const countEl = document.getElementById('bookingSearchCount');
    if (!input || !table) return;

    input.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        const rows = table.querySelectorAll('tbody tr.booking-row');
        let visible = 0;
        rows.forEach(row => {
            const name  = row.dataset.name  || '';
            const ref   = row.dataset.ref   || '';
            const email = row.dataset.email || '';
            const pkg   = row.dataset.pkg   || '';
            const match = !q || name.includes(q) || ref.includes(q) || email.includes(q) || pkg.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        countEl.textContent = q ? `${visible} result(s)` : '';
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
