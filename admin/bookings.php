<?php
$pageTitle = 'Manage Bookings';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

// Handle status updates
// Handle status updates and management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'update_status') {
        $newStatus = strtolower(sanitize($_POST['status'] ?? ''));
        // Admin is strictly not permitted to approve/confirm bookings
        if ($newStatus === 'confirmed') {
            setFlash('error', 'Only cashiers are authorized to approve customer bookings. Administrators can manage records, schedules, and cancellations.');
        } elseif ($newStatus === 'cancelled' && $id > 0) {
            $stmt = $db->prepare("UPDATE bookings SET status = 'Cancelled' WHERE booking_id = ?");
            $stmt->execute([$id]);
            logAudit($_SESSION['user_id'], 'CANCEL_BOOKING', "Admin marked booking #{$id} as Cancelled", 'bookings');
            setFlash('info', "Booking #" . str_pad($id, 5, '0', STR_PAD_LEFT) . " has been marked as Cancelled.");
        }
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM bookings WHERE booking_id = ?");
        $stmt->execute([$id]);
        logAudit($_SESSION['user_id'], 'DELETE_BOOKING', "Deleted booking #{$id}", 'bookings');
        setFlash('success', "Booking deleted.");
    }
    redirect(APP_URL . '/admin/bookings.php');
}

$statusFilter = sanitize($_GET['status'] ?? 'all');
// Include real amount_paid from payments table for accurate payment status
$sql = "
    SELECT b.*, b.booking_id AS id,
           u.name    AS customer_name, u.email AS customer_email,
           p.package_name AS package_name,
           v.venue_name   AS venue_name,
           COALESCE((SELECT SUM(py.amount_paid) FROM payments py WHERE py.booking_id = b.booking_id), 0) AS real_amount_paid
    FROM bookings b
    JOIN users    u ON b.customer_id = u.user_id
    JOIN packages p ON b.package_id  = p.package_id
    LEFT JOIN venues v ON b.venue_id = v.venue_id";
$params = [];

if ($statusFilter !== 'all') {
    $sql .= " WHERE b.status = ?";
    $params[] = ucfirst($statusFilter);
}

$sql .= " ORDER BY b.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>All Bookings</h1>
        <p>Review and manage customer event booking records. <span style="color:var(--accent-gold);"><i class="fa-solid fa-shield-halved"></i> Customer bookings are approved exclusively by Cashiers.</span></p>
    </div>
</div>

<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach (['all', 'pending', 'confirmed', 'completed', 'cancelled'] as $st):
        $icons = ['all'=>'fa-list','pending'=>'fa-clock','confirmed'=>'fa-circle-check','completed'=>'fa-flag-checkered','cancelled'=>'fa-ban'];
        $active = $statusFilter === $st;
    ?>
        <a href="?status=<?= $st ?>" class="filter-tab <?= $active ? 'filter-tab--active' : '' ?>">
            <i class="fa-solid <?= $icons[$st] ?>"></i>
            <?= ucfirst($st) ?>
        </a>
    <?php endforeach; ?>
</div>

<style>
/* ── Filter tabs ── */
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
    position: relative;
    overflow: hidden;
}
.filter-tab:hover {
    color: var(--text-primary);
    background: rgba(255,255,255,0.09);
    border-color: rgba(255,255,255,0.18);
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,0,0,0.3);
}
.filter-tab--active {
    background: linear-gradient(135deg, #e94560 0%, #c0392b 100%);
    color: #fff;
    border-color: rgba(233,69,96,0.4);
    box-shadow: 0 4px 18px rgba(233,69,96,0.45), inset 0 1px 0 rgba(255,255,255,0.15);
}
.filter-tab--active:hover {
    background: linear-gradient(135deg, #f0556e 0%, #d44030 100%);
    box-shadow: 0 8px 26px rgba(233,69,96,0.6), inset 0 1px 0 rgba(255,255,255,0.15);
    color: #fff;
}

/* ── Booking reference chip ── */
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
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.25s ease;
}
.ref-chip:hover {
    background: rgba(78,205,196,0.18);
    border-color: rgba(78,205,196,0.5);
    box-shadow: 0 3px 12px rgba(78,205,196,0.2);
}

/* ── Amount chip ── */
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
    box-shadow: 0 0 10px rgba(245,166,35,0.07), inset 0 1px 0 rgba(255,255,255,0.05);
    transition: all 0.28s ease;
}
.amount-chip:hover {
    border-color: rgba(245,166,35,0.5);
    box-shadow: 0 4px 16px rgba(245,166,35,0.24);
    transform: translateY(-1px);
}
.amount-chip .cur {
    width: 20px; height: 20px;
    background: rgba(245,166,35,0.2);
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.72rem; font-weight: 800;
    color: var(--accent-gold);
    flex-shrink: 0;
}
.amount-chip .amt {
    font-size: 0.9rem;
    font-weight: 800;
    color: #fff;
    line-height: 1;
}

/* ── Action group ── */
.bk-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
}

.btn-photos {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    font-size: 0.8rem;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    border-radius: 10px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.12);
    color: var(--text-primary);
    text-decoration: none;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.06);
    white-space: nowrap;
}
.btn-photos:hover {
    background: rgba(78,205,196,0.15);
    border-color: rgba(78,205,196,0.4);
    color: var(--accent-teal);
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(78,205,196,0.2);
}
.btn-photos i { font-size: 0.82rem; }

.btn-details {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    font-size: 0.8rem;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    border-radius: 10px;
    background: rgba(78,205,196,0.12);
    border: 1px solid rgba(78,205,196,0.3);
    color: var(--accent-teal);
    cursor: pointer;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    white-space: nowrap;
}
.btn-details:hover {
    background: rgba(78,205,196,0.25);
    border-color: rgba(78,205,196,0.5);
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(78,205,196,0.25);
}
.btn-details:active { transform: scale(0.97); }

.btn-bill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    font-size: 0.8rem;
    font-weight: 700;
    font-family: 'Outfit', sans-serif;
    border-radius: 10px;
    background: linear-gradient(135deg, rgba(39,174,96,0.2) 0%, rgba(39,174,96,0.1) 100%);
    border: 1px solid rgba(39,174,96,0.35);
    color: #27ae60;
    text-decoration: none;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
    box-shadow: 0 3px 12px rgba(39,174,96,0.15);
    white-space: nowrap;
}
.btn-bill:hover {
    background: linear-gradient(135deg, #27ae60 0%, #219653 100%);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 7px 20px rgba(39,174,96,0.4);
}

.btn-del-bk {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px; height: 32px;
    border-radius: 9px;
    background: linear-gradient(135deg, rgba(192,57,43,0.18) 0%, rgba(120,36,28,0.25) 100%);
    border: 1px solid rgba(192,57,43,0.3);
    color: #e94560;
    font-size: 0.82rem;
    cursor: pointer;
    transition: all 0.28s cubic-bezier(0.4,0,0.2,1);
}
.btn-del-bk:hover {
    background: linear-gradient(135deg, #c0392b 0%, #7b241c 100%);
    color: #fff;
    border-color: rgba(192,57,43,0.6);
    transform: translateY(-2px);
    box-shadow: 0 5px 16px rgba(192,57,43,0.45);
}
.btn-del-bk:active { transform: scale(0.95); }
</style>

<div class="card">
    <div class="search-bar">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" id="bookingSearchInput" class="form-control"
                   placeholder="Search by customer name, surname, or booking ref..."
                   style="font-size:0.9rem;">
        </div>
        <span id="bookingSearchCount" style="margin-left:12px;font-size:0.82rem;color:var(--text-muted);"></span>
    </div>

    <div class="table-wrapper">
        <table class="table" id="allBookingsTable">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Package</th>
                    <th>Venue</th>
                    <th>Date & Time</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                <tr class="booking-row" data-name="<?= strtolower(htmlspecialchars($b['customer_name'])) ?>" data-ref="<?= strtolower(htmlspecialchars(getBookingRef($b))) ?>">
                    <td>
                        <span class="ref-chip">
                            <i class="fa-solid fa-hashtag" style="font-size:0.7rem;opacity:0.7;"></i>
                            <?= htmlspecialchars(getBookingRef($b)) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($b['customer_email']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($b['package_name']) ?></td>
                    <td><?= htmlspecialchars($b['venue_name'] ?? '—') ?></td>
                    <td style="white-space:nowrap;">
                        <?= formatDate($b['event_date']) ?>
                        <br><small style="color:var(--text-muted);"><?= date('g:i A', strtotime($b['event_time'] ?? '09:00:00')) ?></small>
                        <br><small style="color:var(--accent-gold);"><i class="fa-solid fa-clock"></i> Due: <?= date('M d, Y', strtotime(getBookingPaymentDueDate($b))) ?></small>
                    </td>
                    <td>
                        <div class="amount-chip">
                            <span class="cur">₱</span>
                            <span class="amt"><?= number_format((float)$b['total_amount'], 0, '.', ',') ?></span>
                        </div>
                    </td>
                    <td>
                        <?= statusBadge($b['status']) ?>
                        <?php if (strtolower($b['status']) === 'pending'): ?>
                            <small style="display:block;font-size:0.7rem;color:#f5a623;font-weight:700;margin-top:3px;">
                                <i class="fa-solid fa-clock"></i> Cashier Approval Required
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                            $realPaid  = round((float)($b['real_amount_paid'] ?? 0), 2);
                            $realTotal = round((float)($b['total_amount'] ?? 0), 2);
                            if ($realTotal > 0 && $realPaid >= $realTotal - 0.005) {
                                $payLabel = 'paid'; $payIcon = 'fa-circle-check';
                            } elseif ($realPaid > 0) {
                                $payLabel = 'partial'; $payIcon = 'fa-circle-half-stroke';
                            } else {
                                $payLabel = 'unpaid'; $payIcon = 'fa-circle-xmark';
                            }
                        ?>
                        <a href="<?= APP_URL ?>/admin/bills.php?search=<?= urlencode(getBookingRef($b)) ?>" title="<?= formatCurrency($realPaid) ?> paid of <?= formatCurrency($realTotal) ?>" style="text-decoration:none;">
                            <?= statusBadge($payLabel) ?>
                        </a>
                    </td>
                    <td>
                        <div class="bk-actions">
                            <a href="<?= APP_URL ?>/admin/bills.php?search=<?= urlencode(getBookingRef($b)) ?>" class="btn-bill" title="View Bill & Manage Payments">
                                <i class="fa-solid fa-file-invoice-dollar"></i> Bill
                            </a>
                            <a href="<?= APP_URL ?>/booking_images.php?booking_id=<?= $b['id'] ?>" class="btn-photos" title="View & Upload Photos">
                                <i class="fa-solid fa-camera"></i> Photos
                            </a>
                            <?php
                                $realPaidBtn  = round((float)($b['real_amount_paid'] ?? 0), 2);
                                $realTotalBtn = round((float)($b['total_amount'] ?? 0), 2);
                                $balanceBtn   = round(max(0.0, $realTotalBtn - $realPaidBtn), 2);
                                if ($realTotalBtn > 0 && $realPaidBtn >= $realTotalBtn - 0.005) {
                                    $payStatusBtn = 'Fully Paid'; $payBadgeBtn = 'success';
                                } elseif ($realPaidBtn > 0) {
                                    $payStatusBtn = 'Partial (' . formatCurrency($realPaidBtn) . ' paid)'; $payBadgeBtn = 'warning';
                                } else {
                                    $payStatusBtn = 'Unpaid'; $payBadgeBtn = 'danger';
                                }
                                $modalData = json_encode([
                                    'id'         => $b['id'],
                                    'status'     => $b['status'],
                                    'ref'        => getBookingRef($b),
                                    'customer'   => $b['customer_name'],
                                    'email'      => $b['customer_email'],
                                    'package'    => $b['package_name'],
                                    'venue'      => $b['venue_name'] ?? 'None',
                                    'date'       => formatDate($b['event_date']),
                                    'time'       => date('g:i A', strtotime($b['event_time'] ?? '09:00:00')),
                                    'due'        => date('M d, Y', strtotime(getBookingPaymentDueDate($b))),
                                    'total'      => formatCurrency($realTotalBtn),
                                    'paid'       => formatCurrency($realPaidBtn),
                                    'balance'    => formatCurrency($balanceBtn),
                                    'payStatus'  => $payStatusBtn,
                                    'payBadge'   => $payBadgeBtn,
                                ]);
                            ?>
                            <button type="button" class="btn-details" data-modal="viewDetailsModal"
                                    data-edit='<?= htmlspecialchars($modalData, ENT_QUOTES, 'UTF-8') ?>'
                                    title="View Booking Details">
                                <i class="fa-solid fa-eye"></i> Details
                            </button>

                            <?php if (strtolower($b['status']) !== 'cancelled'): ?>
                            <form method="POST" style="display:inline;margin:0;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="status" value="cancelled">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn-del-bk" style="background:rgba(245,166,35,0.15);color:#f5a623;border-color:rgba(245,166,35,0.3);"
                                        data-confirm="Mark booking <?= htmlspecialchars(getBookingRef($b)) ?> as Cancelled?" title="Mark Cancelled">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            </form>
                            <?php endif; ?>

                            <form method="POST" style="display:inline;margin:0;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn-del-bk" data-confirm="Delete booking <?= htmlspecialchars(getBookingRef($b)) ?>?" title="Delete Record">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══ Booking Details Modal (Admin Management View) ══ -->
<div class="modal-overlay" id="viewDetailsModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-file-invoice"></i> Booking Details</h2>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">

            <!-- Policy Alert -->
            <div id="modal-pending-notice" style="display:none;background:rgba(245,166,35,0.1);border:1px solid rgba(245,166,35,0.3);border-radius:10px;padding:12px 16px;font-size:0.85rem;color:#f5a623;margin-bottom:16px;">
                <i class="fa-solid fa-clock"></i> <strong>Awaiting Cashier Approval:</strong> Only Cashiers are authorized to approve and confirm customer bookings.
            </div>

            <div id="modal-cancelled-notice" style="display:none;background:rgba(233,69,96,0.1);border:1px solid rgba(233,69,96,0.3);border-radius:10px;padding:12px 16px;font-size:0.85rem;color:#e94560;margin-bottom:16px;">
                <i class="fa-solid fa-ban"></i> This booking has been <strong>Cancelled</strong>.
            </div>

            <!-- Booking Info -->
            <div style="background:rgba(78,205,196,0.07);border:1px solid rgba(78,205,196,0.2);border-radius:12px;padding:16px 18px;margin-bottom:18px;">
                <div style="font-size:0.78rem;font-weight:700;color:var(--accent-teal);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">
                    <i class="fa-solid fa-calendar-check"></i> Event Information
                </div>
                <div style="display:grid;gap:8px;font-size:0.9rem;">
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--text-secondary);">Reference:</span>
                        <strong id="modal-ref" style="color:var(--accent-teal);"></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--text-secondary);">Customer:</span>
                        <strong id="modal-customer"></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--text-secondary);">Email:</span>
                        <span id="modal-email" style="color:var(--text-muted);font-size:0.85rem;"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--text-secondary);">Package:</span>
                        <span id="modal-package"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--text-secondary);">Venue:</span>
                        <span id="modal-venue"></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--text-secondary);">Schedule:</span>
                        <span><strong id="modal-date"></strong> at <span id="modal-time"></span></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--text-secondary);">Payment Due Date:</span>
                        <span id="modal-due" style="color:var(--accent-gold);font-weight:600;"></span>
                    </div>
                </div>
            </div>

            <!-- Financial Breakdown -->
            <div style="background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 18px;margin-bottom:18px;">
                <div style="font-size:0.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:12px;">
                    <i class="fa-solid fa-peso-sign"></i> Payment Breakdown
                </div>
                <div style="display:grid;gap:8px;font-size:0.88rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="color:var(--text-secondary);">Total Amount</span>
                        <strong id="modal-total" style="color:var(--accent-gold);"></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span style="color:var(--text-secondary);">Amount Paid</span>
                        <strong id="modal-paid" style="color:#27ae60;"></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid rgba(255,255,255,0.08);padding-top:8px;">
                        <span style="color:var(--text-secondary);">Remaining Balance</span>
                        <strong id="modal-balance" style="color:#e94560;"></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                        <span style="color:var(--text-secondary);">Payment Status</span>
                        <span id="modal-pay-badge"></span>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer" style="display:flex;justify-content:space-between;">
            <button type="button" class="btn btn-secondary" data-modal-close>Close</button>
            <div style="display:flex;gap:8px;">
                <a id="modal-bill-link" href="#" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-file-invoice-dollar"></i> Open Bill
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// ── Live search ──────────────────────────────────────────────
(function() {
    const input   = document.getElementById('bookingSearchInput');
    const countEl = document.getElementById('bookingSearchCount');
    if (!input) return;

    function filterRows() {
        const q    = input.value.trim().toLowerCase();
        const rows = document.querySelectorAll('#allBookingsTable .booking-row');
        let visible = 0;
        rows.forEach(row => {
            const name = row.dataset.name || '';
            const ref  = row.dataset.ref  || '';
            const text = row.textContent.toLowerCase();
            const match = !q || name.includes(q) || ref.includes(q) || text.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        countEl.textContent = q ? `${visible} result(s) for "${input.value}"` : '';
    }

    input.addEventListener('input', filterRows);
    filterRows();
})();

// ── View Details Modal ────────────────────────────────────────
document.querySelectorAll('.btn-details').forEach(btn => {
    btn.addEventListener('click', function () {
        let data = {};
        try { data = JSON.parse(this.dataset.edit || '{}'); } catch(e) {}

        // Populate modal data
        document.getElementById('modal-ref').textContent      = data.ref      || '—';
        document.getElementById('modal-customer').textContent = data.customer || '—';
        document.getElementById('modal-email').textContent    = data.email    || '';
        document.getElementById('modal-package').textContent  = data.package  || '—';
        document.getElementById('modal-venue').textContent    = data.venue    || 'None';
        document.getElementById('modal-date').textContent     = data.date     || '—';
        document.getElementById('modal-time').textContent     = data.time     || '—';
        document.getElementById('modal-due').textContent      = data.due      || '—';

        // Payment breakdown
        document.getElementById('modal-total').textContent   = data.total   || '₱ 0.00';
        document.getElementById('modal-paid').textContent    = data.paid    || '₱ 0.00';
        document.getElementById('modal-balance').textContent = data.balance || '₱ 0.00';

        // Bill link
        document.getElementById('modal-bill-link').href = '<?= APP_URL ?>/admin/bills.php?search=' + encodeURIComponent(data.ref || '');

        // Payment status badge
        const badgeEl    = document.getElementById('modal-pay-badge');
        const badgeClass = data.payBadge || 'secondary';
        const badgeColors = {
            'success':   { bg: 'rgba(39,174,96,0.18)',   color: '#27ae60',  border: 'rgba(39,174,96,0.4)'   },
            'warning':   { bg: 'rgba(245,166,35,0.18)',  color: '#f5a623',  border: 'rgba(245,166,35,0.4)'  },
            'danger':    { bg: 'rgba(233,69,96,0.18)',   color: '#e94560',  border: 'rgba(233,69,96,0.4)'   },
            'secondary': { bg: 'rgba(255,255,255,0.07)', color: '#aaa',     border: 'rgba(255,255,255,0.2)' }
        };
        const bc = badgeColors[badgeClass] || badgeColors['secondary'];
        badgeEl.innerHTML = `<span style="background:${bc.bg};color:${bc.color};border:1px solid ${bc.border};padding:3px 10px;border-radius:20px;font-size:0.8rem;font-weight:700;">${data.payStatus || '—'}</span>`;

        // Context notices
        const pendingEl   = document.getElementById('modal-pending-notice');
        const cancelledEl = document.getElementById('modal-cancelled-notice');
        const currentStatus = (data.status || '').toLowerCase();
        pendingEl.style.display   = currentStatus === 'pending' ? 'block' : 'none';
        cancelledEl.style.display = currentStatus === 'cancelled' ? 'block' : 'none';

        // Open modal
        const overlay = document.getElementById('viewDetailsModal');
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    });
});

// Modal close handlers
document.querySelectorAll('#viewDetailsModal [data-modal-close], #viewDetailsModal .modal-close').forEach(el => {
    el.addEventListener('click', () => {
        document.getElementById('viewDetailsModal').classList.remove('open');
        document.body.style.overflow = '';
    });
});
document.getElementById('viewDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.classList.remove('open');
        document.body.style.overflow = '';
    }
});
</script>
