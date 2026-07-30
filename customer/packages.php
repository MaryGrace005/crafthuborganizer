<?php
$pageTitle = 'Browse Packages';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);

// Ensure generated images are saved in assets/images/
@include_once __DIR__ . '/../copy_images.php';

$db = getDB();
// Fetch active packages with slots count
$packages = $db->query("
    SELECT p.*, p.package_id AS id, p.package_name AS name,
           p.base_price AS price,
           COALESCE(p.max_slots, 5) AS max_slots,
           (SELECT COUNT(*) FROM bookings b
            WHERE b.package_id = p.package_id
            AND b.status NOT IN ('Cancelled')) AS booked_count
    FROM packages p
    WHERE p.status = 'active'
    ORDER BY p.base_price ASC
")->fetchAll();

// Fallback images map by event_type
$defaultImages = [
    'Wedding'    => 'assets/images/packages/wedding.png',
    'Birthday'   => 'assets/images/packages/birthday.png',
    'Debut'      => 'assets/images/packages/debut.png',
    'Christening'=> 'assets/images/packages/christening.png',
];

// Attach components for each package
foreach ($packages as &$pkg) {
    $compStmt = $db->prepare("
        SELECT category, name, description FROM package_components
        WHERE package_id = ? ORDER BY category, name
    ");
    $compStmt->execute([$pkg['package_id'] ?? $pkg['id']]);
    $pkg['components'] = $compStmt->fetchAll();
}
unset($pkg);

$catIcons = [
    'venue'       => 'fa-building',
    'food'        => 'fa-utensils',
    'photography' => 'fa-camera',
    'decoration'  => 'fa-palette'
];
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header" style="background: linear-gradient(135deg, rgba(78,205,196,0.1), rgba(233,69,96,0.07)); border: 1px solid rgba(78,205,196,0.2); border-radius: 20px; padding: 28px 32px; margin-bottom: 28px; position: relative; overflow: hidden;">
    <div style="position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #e94560, #f5a623, #4ecdc4, #9b59b6);"></div>
    <div>
        <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(78,205,196,0.12); border:1px solid rgba(78,205,196,0.3); color:#4ecdc4; padding:5px 14px; border-radius:20px; font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; margin-bottom:12px;">
            <i class="fa-solid fa-box-open"></i> Package Catalog
        </div>
        <h1 style="font-family:'Outfit',sans-serif; font-size:1.9rem; font-weight:800; margin-bottom:6px;">Browse Packages</h1>
        <p style="color: var(--text-secondary); font-size:0.92rem;">Choose from our curated craft event packages — each with complete inclusions &amp; visual previews</p>
    </div>
    <div style="display:flex; gap:24px; flex-shrink:0;">
        <div style="text-align:center;">
            <div style="font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:800; color:#4ecdc4;"><?= count($packages) ?></div>
            <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Packages</div>
        </div>
    </div>
</div>

<?php displayFlash(); ?>

<?php if (empty($packages)): ?>
    <div class="empty-state">
        <i class="fa-solid fa-box-open"></i>
        <h3>No Packages Available</h3>
        <p>Check back soon for exciting craft packages!</p>
    </div>
<?php else: ?>
    <div class="packages-page grid-auto" style="padding-bottom: 40px;">
        <?php foreach ($packages as $pkg):
            $maxSlots    = (int)($pkg['max_slots'] ?? 5);
            $bookedCount = (int)($pkg['booked_count'] ?? 0);
            $available   = max(0, $maxSlots - $bookedCount);
            $isFull      = $available <= 0;
            $pctBooked   = $maxSlots > 0 ? min(100, round($bookedCount / $maxSlots * 100)) : 100;
            $slotColor   = $available <= 1 ? '#e94560' : ($available <= 3 ? '#f5a623' : '#27ae60');
            $components  = $pkg['components'] ?? [];
            
            // Image fallback logic
            $imgPath     = !empty($pkg['image_url']) ? $pkg['image_url'] : ($defaultImages[$pkg['event_type']] ?? 'assets/images/packages/wedding.png');
        ?>
        <div class="package-card" style="overflow:hidden;<?= $isFull ? 'opacity:0.75;' : '' ?>">

            <!-- Package Preview Image -->
            <div style="position:relative;height:180px;overflow:hidden;background:#1a1a2e;">
                <img src="<?= APP_URL ?>/<?= htmlspecialchars($imgPath) ?>"
                     alt="<?= htmlspecialchars($pkg['name'] ?? $pkg['package_name']) ?>"
                     style="width:100%;height:100%;object-fit:cover;display:block;transition:transform 0.4s ease;"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                
                <!-- Fallback icon container if image fails -->
                <div style="display:none;width:100%;height:100%;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--accent-teal),var(--accent-purple));color:#fff;font-size:3rem;">
                    <i class="fa-solid fa-palette"></i>
                </div>

                <div style="position:absolute;top:12px;left:12px;background:rgba(15,15,26,0.75);backdrop-filter:blur(6px);color:var(--accent-teal);border:1px solid rgba(78,205,196,0.3);padding:4px 12px;border-radius:20px;font-size:0.78rem;font-weight:600;display:flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-gift"></i> <?= htmlspecialchars($pkg['event_type']) ?>
                </div>

                <?php if ($isFull): ?>
                    <div style="position:absolute;top:12px;right:12px;background:rgba(233,69,96,0.9);color:#fff;padding:4px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;">
                        <i class="fa-solid fa-ban"></i> FULL
                    </div>
                <?php endif; ?>
            </div>

            <div class="package-card-header" style="padding-top:14px;">
                <div class="package-card-title"><?= htmlspecialchars($pkg['name'] ?? $pkg['package_name']) ?></div>
                <div class="package-price">
                    <?= formatCurrency($pkg['price'] ?? $pkg['base_price']) ?>
                    <span>/ booking</span>
                </div>
            </div>

            <div class="package-card-body">
                <p class="package-description">
                    <?= htmlspecialchars($pkg['description'] ?: 'A wonderful craft experience awaits you!') ?>
                </p>

                <!-- Included Details Section for Customer -->
                <div style="margin-top:14px;padding:12px;background:rgba(78,205,196,0.06);border:1px solid rgba(78,205,196,0.2);border-radius:var(--radius-sm);">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <span style="color:var(--accent-teal);font-weight:700;font-size:0.82rem;text-transform:uppercase;letter-spacing:0.04em;">
                            <i class="fa-solid fa-list-check" style="margin-right:4px;"></i> Included Details:
                        </span>
                        <span class="badge badge-info" style="font-size:0.72rem;padding:2px 7px;">
                            <?= count($components) ?> Item<?= count($components) !== 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <?php if (!empty($components)): ?>
                        <ul style="list-style:none;padding:0;margin:0;display:grid;gap:6px;">
                            <?php foreach ($components as $c): ?>
                                <li style="display:flex;align-items:flex-start;gap:8px;font-size:0.83rem;color:var(--text-primary);">
                                    <i class="fa-solid <?= $catIcons[$c['category']] ?? 'fa-circle-check' ?>"
                                       style="color:var(--accent-teal);margin-top:3px;font-size:0.8rem;width:14px;text-align:center;"
                                       title="<?= ucfirst($c['category']) ?>"></i>
                                    <div>
                                        <strong><?= htmlspecialchars($c['name']) ?></strong>
                                        <?php if (!empty($c['description'])): ?>
                                            <div style="font-size:0.75rem;color:var(--text-secondary);"><?= htmlspecialchars($c['description']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div style="font-size:0.8rem;color:var(--text-muted);font-style:italic;">
                            Standard craft package setup and coordination included.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Slot Availability -->
                <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border-color);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <span style="font-size:0.8rem;font-weight:600;color:var(--text-secondary);">
                            <i class="fa-solid fa-calendar-days"></i> Slot Availability
                        </span>
                        <?php if ($isFull): ?>
                            <span style="font-size:0.8rem;font-weight:700;color:#e94560;">
                                <i class="fa-solid fa-circle-xmark"></i> Fully Booked
                            </span>
                        <?php else: ?>
                            <span style="font-size:0.8rem;font-weight:700;color:<?= $slotColor ?>;">
                                <?= $available ?> of <?= $maxSlots ?> slot<?= $maxSlots > 1 ? 's' : '' ?> left
                            </span>
                        <?php endif; ?>
                    </div>
                    <!-- Progress Bar -->
                    <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:20px;height:8px;overflow:hidden;">
                        <div style="height:100%;width:<?= $pctBooked ?>%;background:<?= $slotColor ?>;border-radius:20px;transition:width 0.3s ease;"></div>
                    </div>
                    <?php if ($isFull): ?>
                        <p style="font-size:0.78rem;color:var(--text-muted);margin-top:6px;text-align:center;">
                            This package is currently fully booked. Please check back later.
                        </p>
                    <?php elseif ($available <= 2): ?>
                        <p style="font-size:0.78rem;color:#f5a623;margin-top:6px;font-weight:600;">
                            <i class="fa-solid fa-triangle-exclamation"></i> Only <?= $available ?> slot<?= $available > 1 ? 's' : '' ?> remaining — book now!
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="package-card-footer">
                <?php if ($isFull): ?>
                    <button class="btn btn-secondary btn-block" disabled style="cursor:not-allowed;opacity:0.6;">
                        <i class="fa-solid fa-ban"></i> Fully Booked
                    </button>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/customer/booking.php?package_id=<?= $pkg['id'] ?>" class="btn btn-primary btn-block">
                        <i class="fa-solid fa-calendar-plus"></i> Book This Package
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
