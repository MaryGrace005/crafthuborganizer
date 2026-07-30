<?php
$pageTitle = 'Payments';
require_once __DIR__ . '/../includes/header.php';
requireRole(['cashier']);

$db     = getDB();
$filter = sanitize($_GET['filter'] ?? 'unpaid');
$allowed = ['all','unpaid','partial','paid'];
if (!in_array($filter, $allowed)) $filter = 'unpaid';

$sql = "SELECT b.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
               p.name AS package_name, v.name AS venue_name
        FROM bookings b
        JOIN users u ON b.customer_id = u.id
        JOIN packages p ON b.package_id = p.id
        LEFT JOIN venues v ON b.venue_id = v.id
        WHERE b.status != 'cancelled'";

$params = [];
if ($filter !== 'all') {
    $sql .= " AND b.payment_status = ?";
    $params[] = $filter;
}
$sql .= " ORDER BY b.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Payments</h1>
        <p>Manage and process booking payments</p>
    </div>
</div>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php foreach ($allowed as $f): ?>
        <a href="?filter=<?= $f ?>" class="btn btn-sm <?= $filter === $f ? 'btn-primary' : 'btn-secondary' ?>">
            <?= ucfirst($f) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <div class="search-bar" style="margin:0;flex:1;">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-search"></i>
                <input type="text" class="form-control" placeholder="Search bookings..." data-search-table="paymentsTable">
            </div>
        </div>
    </div>

    <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-money-bill-wave"></i>
            <h3>No Bookings Found</h3>
            <p>No bookings match the selected filter.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <table class="table" id="paymentsTable">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Package</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b):
                        $balance = $b['total_amount'] - $b['amount_paid'];
                    ?>
                    <tr>
                        <td><strong style="color:var(--accent-teal);"><?= $b['booking_reference'] ?></strong></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($b['customer_name']) ?></div>
                            <div style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($b['customer_phone'] ?? '') ?></div>
                        </td>
                        <td><?= htmlspecialchars($b['package_name']) ?></td>
                        <td><?= formatCurrency($b['total_amount']) ?></td>
                        <td style="color:#27ae60;"><?= formatCurrency($b['amount_paid']) ?></td>
                        <td style="color:<?= $balance > 0 ? 'var(--accent-red)' : '#27ae60'; ?>;">
                            <?= formatCurrency($balance) ?>
                        </td>
                        <td><?= statusBadge($b['status']) ?></td>
                        <td><?= statusBadge($b['payment_status']) ?></td>
                        <td>
                            <?php if ($b['payment_status'] !== 'paid'): ?>
                                <a href="<?= APP_URL ?>/cashier/process_payment.php?id=<?= $b['id'] ?>"
                                   class="btn btn-success btn-sm">
                                    <i class="fa-solid fa-money-bill"></i> Pay
                                </a>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-check"></i> Paid</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
