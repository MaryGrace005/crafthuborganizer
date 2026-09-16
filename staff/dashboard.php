<?php
$pageTitle = 'Staff Dashboard';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);

$user  = getCurrentUser();
$stats = getDashboardStats('staff', $user['id']);
$db    = getDB();
$userId = $user['user_id'] ?? $user['id'];

// Handle quick-approve from dashboard (approve only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_booking') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $chk = $db->prepare("SELECT b.*, u.name AS customer_name FROM bookings b JOIN users u ON b.customer_id = u.user_id WHERE b.booking_id = ?");
    $chk->execute([$bookingId]);
    $bk = $chk->fetch();
    if ($bk) {
        $ref = getBookingRef($bk);
        ensureApprovalColumns($db);
        $now = date('Y-m-d H:i:s');
        $db->prepare("UPDATE bookings SET status = 'Confirmed', approved_at = ?, approved_by = ? WHERE booking_id = ?")
           ->execute([$now, $userId, $bookingId]);
        updateBookingPaymentStatus($bookingId);

        $timeFormatted = date('M d, Y g:i A', strtotime($now));
        logAudit($userId, 'APPROVE_BOOKING', "Cashier approved booking #{$bookingId} ({$ref}) on {$timeFormatted} from Dashboard", 'bookings');
        setFlash('success', "✓ Booking <strong>{$ref}</strong> approved on <strong>{$timeFormatted}</strong>! Payment can now be collected.");
    }
    redirect(APP_URL . '/staff/dashboard.php');
}

// Pending customer bookings awaiting cashier approval
$pendingBookings = $db->query("
    SELECT b.*, b.booking_id AS id, u.name AS customer_name, u.email AS customer_email, p.package_name, v.venue_name
    FROM bookings b
    JOIN users u ON b.customer_id = u.user_id
    JOIN packages p ON b.package_id = p.package_id
    LEFT JOIN venues v ON b.venue_id = v.venue_id
    WHERE b.status = 'Pending'
    ORDER BY b.created_at ASC
")->fetchAll();
$pendingBookingsCount = count($pendingBookings);

// Bookings needing payment (Confirmed bookings with remaining balance)
$stmt = $db->query("
    SELECT b.*, b.booking_id AS id, u.name AS customer_name, p.package_name AS package_name,
           (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS calc_paid
    FROM bookings b
    JOIN users u ON b.customer_id = u.user_id
    JOIN packages p ON b.package_id = p.package_id
    WHERE b.status = 'Confirmed'
      AND (b.total_amount - (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id)) > 0
    ORDER BY b.created_at DESC LIMIT 8
");
$pendingPayments = $stmt->fetchAll();

// Today's payments
$todayStmt = $db->prepare("
    SELECT py.*, py.payment_id AS id, u.name AS customer_name
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN users u ON b.customer_id = u.user_id
    WHERE py.cashier_id = ? AND DATE(py.payment_date) = CURDATE()
    ORDER BY py.payment_date DESC
");
$todayStmt->execute([$userId]);
$todayPayments = $todayStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Staff &amp; Cashier Dashboard</h1>
        <p><?= date('l, F j, Y') ?></p>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="<?= APP_URL ?>/staff/bookings.php?status=pending" class="btn btn-secondary">
            <i class="fa-solid fa-stamp"></i> Review Bookings
            <?php if ($pendingBookingsCount > 0): ?>
                <span class="badge badge-warning" style="margin-left:6px;"><?= $pendingBookingsCount ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= APP_URL ?>/staff/customers.php" class="btn btn-primary">
            <i class="fa-solid fa-user-plus"></i> Register Customer
        </a>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid">
    <a href="<?= APP_URL ?>/staff/bookings.php?status=pending" class="stat-card" style="--stat-color:#f5a623;text-decoration:none;cursor:pointer;">
        <div class="stat-icon" style="background:rgba(245,166,35,0.2);color:#f5a623;">
            <i class="fa-solid fa-stamp"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $pendingBookingsCount ?></div>
            <div class="stat-label">Awaiting Approval <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.68rem;opacity:0.7;"></i></div>
        </div>
    </a>
    <a href="<?= APP_URL ?>/staff/bills.php?status=outstanding" class="stat-card" style="--stat-color:#e94560;text-decoration:none;cursor:pointer;">
        <div class="stat-icon" style="background:rgba(233,69,96,0.2);color:#e94560;">
            <i class="fa-solid fa-clock"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['pending_payments'] ?></div>
            <div class="stat-label">Pending Payments <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.68rem;opacity:0.7;"></i></div>
        </div>
    </a>
    <a href="<?= APP_URL ?>/staff/collection.php" class="stat-card" style="--stat-color:#27ae60;text-decoration:none;cursor:pointer;">
        <div class="stat-icon" style="background:rgba(39,174,96,0.2);color:#27ae60;">
            <i class="fa-solid fa-peso-sign"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= formatCurrency($stats['total_collected']) ?></div>
            <div class="stat-label">My Collections <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.68rem;opacity:0.7;"></i></div>
        </div>
    </a>
    <a href="<?= APP_URL ?>/staff/collection.php?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="stat-card" style="--stat-color:#4ecdc4;text-decoration:none;cursor:pointer;">
        <div class="stat-icon" style="background:rgba(78,205,196,0.2);color:#4ecdc4;">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['today_payments'] ?></div>
            <div class="stat-label">Today's Collections <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:0.68rem;opacity:0.7;"></i></div>
        </div>
    </a>
</div>

<!-- Pending Bookings Approval Alert Card -->
<?php if ($pendingBookingsCount > 0): ?>
<div class="card" style="border:1px solid rgba(245,166,35,0.4);background:rgba(245,166,35,0.04);margin-bottom:24px;">
    <div class="card-header" style="border-bottom:1px solid rgba(245,166,35,0.2);">
        <h2 class="card-title" style="color:var(--accent-gold);">
            <i class="fa-solid fa-clock"></i> <?= $pendingBookingsCount ?> Customer Booking<?= $pendingBookingsCount > 1 ? 's' : '' ?> Awaiting Cashier Approval
        </h2>
        <a href="<?= APP_URL ?>/staff/bookings.php?status=pending" class="btn btn-warning btn-sm">
            <i class="fa-solid fa-arrow-right"></i> Manage All Approvals
        </a>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;padding:16px 20px;">
        <?php foreach (array_slice($pendingBookings, 0, 5) as $pb):
            $pbRef = getBookingRef($pb);
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--bg-card);border-radius:var(--radius-sm);border:1px solid var(--border-color);flex-wrap:wrap;gap:12px;">
            <div>
                <div style="font-weight:700;color:#fff;font-size:0.92rem;">
                    <?= htmlspecialchars($pb['customer_name']) ?>
                    <span style="font-size:0.78rem;color:var(--accent-teal);margin-left:8px;font-weight:800;"><?= htmlspecialchars($pbRef) ?></span>
                </div>
                <div style="font-size:0.8rem;color:var(--text-secondary);margin-top:2px;">
                    <?= htmlspecialchars($pb['package_name']) ?> • Event: <?= formatDate($pb['event_date']) ?> <?= $pb['venue_name'] ? '• ' . htmlspecialchars($pb['venue_name']) : '' ?>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="font-weight:800;color:var(--accent-gold);font-size:0.95rem;">
                    <?= formatCurrency($pb['total_amount']) ?>
                </div>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="approve_booking">
                    <input type="hidden" name="booking_id" value="<?= $pb['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm" style="font-weight:700;padding:6px 14px;"
                            onclick="return confirm('Approve booking <?= htmlspecialchars(addslashes($pbRef)) ?> for <?= htmlspecialchars(addslashes($pb['customer_name'])) ?>?')">
                        <i class="fa-solid fa-check"></i> Approve
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="grid-2" style="align-items:start;">

    <!-- Pending Payments -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-circle-exclamation" style="color:var(--accent-gold);"></i> Needs Payment</h2>
            <a href="<?= APP_URL ?>/staff/payments.php" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <?php if (empty($pendingPayments)): ?>
            <div class="empty-state" style="padding:30px;">
                <i class="fa-solid fa-circle-check" style="color:var(--accent-teal);"></i>
                <p>All payments are up to date!</p>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($pendingPayments as $b): 
                    $totAmt  = (float)($b['total_amount'] ?? 0);
                    $amtPaid = (float)($b['calc_paid'] > 0 ? $b['calc_paid'] : ($b['amount_paid'] ?? 0));
                    $balance = max(0.0, $totAmt - $amtPaid);
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:var(--bg-card);border-radius:var(--radius-sm);border:1px solid var(--border-color);">
                    <div>
                        <div style="font-size:0.88rem;font-weight:600;"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars(getBookingRef($b)) ?> • <?= htmlspecialchars($b['package_name']) ?></div>
                        <div style="font-size:0.75rem;margin-top:2px;">
                            <span style="color:#27ae60;font-weight:600;"><?= formatCurrency($amtPaid) ?> Paid</span>
                            <span style="color:var(--text-muted);">/ <?= formatCurrency($totAmt) ?></span>
                        </div>
                    </div>
                    <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                        <div style="color:var(--accent-red);font-weight:700;font-size:0.9rem;"><?= formatCurrency($balance) ?> Due</div>
                        <a href="<?= APP_URL ?>/staff/process_payment.php?id=<?= $b['id'] ?>" class="btn btn-success btn-sm">
                            <i class="fa-solid fa-money-bill"></i> Pay
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Today's Transactions -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-receipt"></i> Today's Collections</h2>
        </div>
        <?php if (empty($todayPayments)): ?>
            <div class="empty-state" style="padding:30px;">
                <i class="fa-solid fa-coins"></i>
                <p>No collections recorded today yet.</p>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <?php foreach ($todayPayments as $py): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:rgba(39,174,96,0.05);border-radius:var(--radius-sm);border:1px solid rgba(39,174,96,0.15);">
                    <div>
                        <div style="font-size:0.88rem;font-weight:600;"><?= htmlspecialchars($py['customer_name']) ?></div>
                        <div style="font-size:0.78rem;color:var(--text-secondary);"><a href="<?= APP_URL ?>/staff/bills.php?search=<?= urlencode(getBookingRef($py)) ?>" style="color:var(--accent-teal);text-decoration:none;"><?= htmlspecialchars(getBookingRef($py)) ?></a> • <?= ucwords(str_replace('_',' ', $py['payment_method'] ?? 'cash')) ?></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span style="color:#27ae60;font-weight:700;"><?= formatCurrency($py['amount_paid']) ?></span>
                        <a href="<?= APP_URL ?>/receipt.php?id=<?= $py['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" style="font-size:0.75rem;padding:3px 8px;" title="Print Receipt">
                            <i class="fa-solid fa-receipt"></i> Receipt
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
