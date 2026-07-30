<?php
$pageTitle = 'Payment History';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);

$user = getCurrentUser();
$db   = getDB();

$stmt = $db->prepare("
    SELECT py.*, b.booking_reference, b.total_amount, p.name AS package_name,
           u.name AS cashier_name
    FROM payments py
    JOIN bookings b ON py.booking_id = b.id
    JOIN packages p ON b.package_id = p.id
    JOIN users u ON py.cashier_id = u.id
    WHERE b.customer_id = ?
    ORDER BY py.payment_date DESC
");
$stmt->execute([$user['id']]);
$payments = $stmt->fetchAll();

// Total paid
$totalStmt = $db->prepare("SELECT COALESCE(SUM(py.amount_paid),0) FROM payments py JOIN bookings b ON py.booking_id = b.id WHERE b.customer_id = ?");
$totalStmt->execute([$user['id']]);
$totalPaid = $totalStmt->fetchColumn();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Payment History</h1>
        <p>All payments made for your bookings</p>
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
                        <th>#</th>
                        <th>Booking Ref</th>
                        <th>Package</th>
                        <th>Amount Paid</th>
                        <th>Method</th>
                        <th>Reference No.</th>
                        <th>Processed By</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $i => $py): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong style="color:var(--accent-teal);"><?= $py['booking_reference'] ?></strong></td>
                        <td><?= htmlspecialchars($py['package_name']) ?></td>
                        <td><strong style="color:var(--accent-gold);"><?= formatCurrency($py['amount_paid']) ?></strong></td>
                        <td>
                            <span class="badge badge-info">
                                <?= ucwords(str_replace('_', ' ', $py['payment_method'])) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($py['reference_no'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($py['cashier_name']) ?></td>
                        <td><?= formatDateTime($py['payment_date']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
