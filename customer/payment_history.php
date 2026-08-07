<?php
$pageTitle = 'Payment History';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);
requireApproved();

$user = getCurrentUser();
$db   = getDB();

$userId = $user['user_id'] ?? $user['id'];

$stmt = $db->prepare("
    SELECT py.*, py.payment_id AS id, b.total_amount, p.package_name AS package_name,
           u.name AS cashier_name,
           (SELECT COALESCE(SUM(amount_paid),0) FROM payments WHERE booking_id = b.booking_id) AS booking_total_paid
    FROM payments py
    JOIN bookings b ON py.booking_id = b.booking_id
    JOIN packages p ON b.package_id = p.package_id
    JOIN users u ON py.cashier_id = u.user_id
    WHERE b.customer_id = ?
    ORDER BY py.payment_date DESC
");
$stmt->execute([$userId]);
$payments = $stmt->fetchAll();

// Total paid
$totalStmt = $db->prepare("SELECT COALESCE(SUM(py.amount_paid),0) FROM payments py JOIN bookings b ON py.booking_id = b.booking_id WHERE b.customer_id = ?");
$totalStmt->execute([$userId]);
$totalPaid = $totalStmt->fetchColumn();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Payment History</h1>
        <p>All transaction records, downpayments, and receipts for your bookings</p>
    </div>
    <div style="text-align:right;">
        <div style="font-size:0.85rem;color:var(--text-secondary);">Total Amount Paid</div>
        <div style="font-family:'Outfit',sans-serif;font-size:1.8rem;font-weight:800;color:var(--accent-gold);">
            <?= formatCurrency($totalPaid) ?>
        </div>
    </div>
</div>

<div class="card">
    <?php if (empty($payments)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-receipt"></i>
            <h3>No Payment Records</h3>
            <p>Your payment history will appear here once payments are processed.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Receipt OR#</th>
                        <th>Booking Ref</th>
                        <th>Package</th>
                        <th>Payment Type</th>
                        <th>Amount Paid</th>
                        <th>Booking Balance</th>
                        <th>Method</th>
                        <th>Processed By</th>
                        <th>Date</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $py): 
                        $totalCost = (float)($py['total_amount'] ?? 0);
                        $totalPaidForBooking = (float)($py['booking_total_paid'] ?? 0);
                        $remBalance = max(0.0, $totalCost - $totalPaidForBooking);
                    ?>
                    <tr>
                        <td><strong style="color:var(--accent-gold);"><?= htmlspecialchars($py['or_number'] ?? ('OR-' . $py['id'])) ?></strong></td>
                        <td><strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($py)) ?></strong></td>
                        <td><?= htmlspecialchars($py['package_name']) ?></td>
                        <td>
                            <?php if (($py['payment_type'] ?? '') === 'downpayment'): ?>
                                <span class="badge badge-warning"><i class="fa-solid fa-piggy-bank"></i> Downpayment</span>
                            <?php elseif (($py['payment_type'] ?? '') === 'balance'): ?>
                                <span class="badge badge-info"><i class="fa-solid fa-scale-balanced"></i> Balance</span>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Full Payment</span>
                            <?php endif; ?>
                        </td>
                        <td><strong style="color:#27ae60;font-size:0.95rem;"><?= formatCurrency($py['amount_paid']) ?></strong></td>
                        <td>
                            <?php if ($remBalance > 0): ?>
                                <span style="color:var(--accent-red);font-weight:600;"><?= formatCurrency($remBalance) ?></span>
                            <?php else: ?>
                                <span class="badge badge-success">Paid Off</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-secondary">
                                <?= ucwords(str_replace('_', ' ', $py['payment_method'] ?? 'cash')) ?>
                            </span>
                            <?php if (!empty($py['reference_no'])): ?>
                                <div style="font-size:0.72rem;color:var(--text-muted);">Ref: <?= htmlspecialchars($py['reference_no']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($py['cashier_name']) ?></td>
                        <td><?= formatDateTime($py['payment_date']) ?></td>
                        <td>
                            <a href="<?= APP_URL ?>/receipt.php?id=<?= (int)$py['id'] ?>" target="_blank" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-file-invoice"></i> View Receipt
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
