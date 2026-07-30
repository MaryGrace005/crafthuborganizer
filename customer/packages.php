<?php
$pageTitle = 'Browse Packages';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);

$db       = getDB();
$packages = $db->query("SELECT * FROM packages WHERE status = 'active' ORDER BY price ASC")->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Browse Packages</h1>
        <p>Choose from our curated craft event packages</p>
    </div>
</div>

<?php if (empty($packages)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-box-open"></i>
        <h3>No Packages Available</h3>
        <p>Check back soon for exciting craft packages!</p>
    </div>
<?php else: ?>
    <div class="grid-auto">
        <?php foreach ($packages as $pkg): ?>
        <div class="package-card">
            <div class="package-card-header">
                <div class="package-icon">
                    <i class="fa-solid fa-palette"></i>
                </div>
                <div class="package-card-title"><?= htmlspecialchars($pkg['name']) ?></div>
                <div class="package-price">
                    <?= formatCurrency($pkg['price']) ?>
                    <span>/ booking</span>
                </div>
            </div>
            <div class="package-card-body">
                <div class="package-meta">
                    <div class="package-meta-item">
                        <i class="fa-solid fa-users"></i>
                        Up to <?= $pkg['capacity'] ?> guests
                    </div>
                    <div class="package-meta-item">
                        <i class="fa-solid fa-clock"></i>
                        <?= $pkg['duration_hours'] ?> hours
                    </div>
                </div>
                <p class="package-description">
                    <?= htmlspecialchars($pkg['description'] ?: 'A wonderful craft experience awaits you!') ?>
                </p>

                <?php
                // Get components for this package
                $compStmt = $db->prepare("
                    SELECT c.name FROM components c
                    JOIN package_components pc ON pc.component_id = c.id
                    WHERE pc.package_id = ? LIMIT 5
                ");
                $compStmt->execute([$pkg['id']]);
                $components = $compStmt->fetchAll();
                ?>
                <?php if (!empty($components)): ?>
                <div class="mt-2" style="font-size:0.82rem;">
                    <span style="color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.04em;">Includes:</span>
                    <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ($components as $c): ?>
                            <span style="background:rgba(78,205,196,0.1);color:var(--accent-teal);border:1px solid rgba(78,205,196,0.2);border-radius:20px;padding:3px 10px;font-size:0.78rem;">
                                <?= htmlspecialchars($c['name']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="package-card-footer">
                <a href="<?= APP_URL ?>/customer/booking.php?package_id=<?= $pkg['id'] ?>" class="btn btn-primary btn-block">
                    <i class="fa-solid fa-calendar-plus"></i> Book This Package
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
