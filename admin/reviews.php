<?php
$pageTitle = 'Customer Reviews';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);

$db = getDB();

// Handle status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $reviewId = (int)($_POST['review_id'] ?? 0);
    if ($reviewId > 0) {
        if ($action === 'hide') {
            $db->prepare("UPDATE reviews SET status = 'hidden' WHERE review_id = ?")->execute([$reviewId]);
            setFlash('success', 'Review hidden.');
        } elseif ($action === 'publish') {
            $db->prepare("UPDATE reviews SET status = 'published' WHERE review_id = ?")->execute([$reviewId]);
            setFlash('success', 'Review published.');
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM reviews WHERE review_id = ?")->execute([$reviewId]);
            setFlash('success', 'Review deleted.');
        }
    }
    redirect(APP_URL . '/admin/reviews.php');
}

$filterStatus = $_GET['status'] ?? 'all';
$where = $filterStatus !== 'all' ? "WHERE r.status = " . $db->quote($filterStatus) : '';

$reviews = $db->query("
    SELECT r.*, u.name AS customer_name, p.package_name, b.event_date, b.booking_reference
    FROM reviews r
    JOIN users u ON r.customer_id = u.user_id
    JOIN bookings b ON r.booking_id = b.booking_id
    JOIN packages p ON b.package_id = p.package_id
    {$where}
    ORDER BY r.created_at DESC
    LIMIT 200
")->fetchAll();

// Aggregate stats
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        ROUND(AVG(rating), 1) AS avg_rating,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS five_star,
        SUM(CASE WHEN would_recommend = 1 THEN 1 ELSE 0 END) AS recommend_count
    FROM reviews WHERE status = 'published'
")->fetch();

require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="page-header">
    <div>
        <h1><i class="fa-solid fa-star" style="color:var(--accent-gold);"></i> Customer Reviews</h1>
        <p>Manage and monitor event feedback</p>
    </div>
</div>

<!-- Stats Row -->
<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card" style="--stat-color:var(--accent-gold);">
        <div class="stat-icon" style="background:rgba(245,166,35,0.2);color:var(--accent-gold);">
            <i class="fa-solid fa-star"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['avg_rating'] ?? '—' ?></div>
            <div class="stat-label">Avg Rating</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#27ae60;">
        <div class="stat-icon" style="background:rgba(39,174,96,0.2);color:#27ae60;">
            <i class="fa-solid fa-comment"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
            <div class="stat-label">Total Reviews</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:var(--accent-teal);">
        <div class="stat-icon" style="background:rgba(78,205,196,0.2);color:var(--accent-teal);">
            <i class="fa-solid fa-crown"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['five_star'] ?? 0 ?></div>
            <div class="stat-label">5-Star Reviews</div>
        </div>
    </div>
    <div class="stat-card" style="--stat-color:#4ecdc4;">
        <div class="stat-icon" style="background:rgba(78,205,196,0.2);color:#4ecdc4;">
            <i class="fa-solid fa-thumbs-up"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= $stats['recommend_count'] ?? 0 ?></div>
            <div class="stat-label">Recommenders</div>
        </div>
    </div>
</div>

<!-- Filter -->
<div style="display:flex;gap:8px;margin-bottom:20px;">
    <?php foreach (['all'=>'All','published'=>'Published','hidden'=>'Hidden'] as $val=>$label): ?>
    <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-secondary' ?>">
        <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (empty($reviews)): ?>
<div class="card">
    <div class="empty-state" style="padding:50px;">
        <i class="fa-solid fa-star" style="color:var(--accent-gold);"></i>
        <p>No reviews found.</p>
    </div>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:14px;">
<?php foreach ($reviews as $r):
    $stars = (int)$r['rating'];
    $isHidden = $r['status'] === 'hidden';
?>
<div class="card" style="opacity:<?= $isHidden ? '0.6' : '1' ?>;border-left:4px solid <?= $isHidden ? '#555' : 'var(--accent-gold)' ?>;">
    <div style="padding:18px 20px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
                    <strong style="font-size:1rem;"><?= htmlspecialchars($r['customer_name']) ?></strong>
                    <span style="font-size:0.78rem;color:var(--accent-teal);"><?= htmlspecialchars($r['booking_reference'] ?? '') ?></span>
                    <span style="color:#f5a623;font-size:1rem;"><?= str_repeat('★', $stars) ?><?= str_repeat('☆', 5 - $stars) ?></span>
                    <?php if ($r['would_recommend']): ?>
                    <span style="font-size:0.75rem;color:#27ae60;font-weight:700;">👍 Recommends</span>
                    <?php endif; ?>
                    <?php if ($isHidden): ?>
                    <span class="badge badge-secondary">Hidden</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.8rem;color:var(--text-secondary);margin-bottom:8px;">
                    <?= htmlspecialchars($r['package_name']) ?> • Event: <?= formatDate($r['event_date']) ?> • Reviewed: <?= date('M j, Y', strtotime($r['created_at'])) ?>
                </div>
                <?php if (!empty($r['service_rating']) || !empty($r['venue_rating']) || !empty($r['food_rating'])): ?>
                <div style="display:flex;gap:12px;margin-bottom:8px;font-size:0.78rem;color:var(--text-secondary);">
                    <?php if ($r['service_rating']): ?><span>🤝 Service: <strong style="color:#f5a623;"><?= str_repeat('★', (int)$r['service_rating']) ?></strong></span><?php endif; ?>
                    <?php if ($r['venue_rating']): ?><span>🏛 Venue: <strong style="color:#f5a623;"><?= str_repeat('★', (int)$r['venue_rating']) ?></strong></span><?php endif; ?>
                    <?php if ($r['food_rating']): ?><span>🍽 Food: <strong style="color:#f5a623;"><?= str_repeat('★', (int)$r['food_rating']) ?></strong></span><?php endif; ?>
                </div>
                <?php endif; ?>
                <div style="font-style:italic;color:var(--text-secondary);line-height:1.6;font-size:0.88rem;">
                    "<?= nl2br(htmlspecialchars($r['review_text'])) ?>"
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;min-width:100px;">
                <?php if ($isHidden): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="publish">
                    <input type="hidden" name="review_id" value="<?= $r['review_id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm" style="width:100%;">
                        <i class="fa-solid fa-eye"></i> Publish
                    </button>
                </form>
                <?php else: ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="hide">
                    <input type="hidden" name="review_id" value="<?= $r['review_id'] ?>">
                    <button type="submit" class="btn btn-secondary btn-sm" style="width:100%;">
                        <i class="fa-solid fa-eye-slash"></i> Hide
                    </button>
                </form>
                <?php endif; ?>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this review permanently?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="review_id" value="<?= $r['review_id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" style="width:100%;font-size:0.78rem;">
                        <i class="fa-solid fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
