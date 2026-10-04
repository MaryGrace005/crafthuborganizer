<?php
$pageTitle = 'Leave a Review';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);

$db     = getDB();
$user   = getCurrentUser();
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

$bookingId = (int)($_GET['id'] ?? 0);
if (!$bookingId) {
    setFlash('error', 'Invalid booking.');
    redirect(APP_URL . '/customer/bookings.php');
}

// Fetch booking — must be Completed and belong to this customer
$stmt = $db->prepare("
    SELECT b.*, b.booking_id AS id, p.package_name
    FROM bookings b
    JOIN packages p ON b.package_id = p.package_id
    WHERE b.booking_id = ? AND b.customer_id = ? AND b.status = 'Completed'
");
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'You can only leave reviews for completed events.');
    redirect(APP_URL . '/customer/bookings.php');
}

// Check if already reviewed
$existingReview = $db->prepare("SELECT * FROM reviews WHERE booking_id = ? AND customer_id = ?");
$existingReview->execute([$bookingId, $userId]);
$existing = $existingReview->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existing) {
    $rating        = (int)($_POST['rating'] ?? 0);
    $reviewText    = trim($_POST['review_text'] ?? '');
    $serviceRating = (int)($_POST['service_rating'] ?? 0);
    $venueRating   = (int)($_POST['venue_rating'] ?? 0);
    $foodRating    = (int)($_POST['food_rating'] ?? 0);
    $wouldRecommend = isset($_POST['would_recommend']) ? 1 : 0;

    if ($rating < 1 || $rating > 5) $errors[] = 'Please select an overall rating (1-5 stars).';
    if (strlen($reviewText) < 20) $errors[] = 'Review must be at least 20 characters.';

    if (empty($errors)) {
        try {
            $ins = $db->prepare("
                INSERT INTO reviews (booking_id, customer_id, rating, service_rating, venue_rating, food_rating,
                                     review_text, would_recommend, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'published', NOW())
            ");
            $ins->execute([
                $bookingId, $userId, $rating,
                $serviceRating ?: null, $venueRating ?: null, $foodRating ?: null,
                $reviewText, $wouldRecommend
            ]);

            // Notify admin
            notifyAdminsAndStaff(
                '⭐ New Review Submitted',
                "{$user['name']} left a {$rating}-star review for booking " . getBookingRef($booking) . " ({$booking['package_name']}).",
                'info',
                APP_URL . '/admin/reviews.php'
            );

            logAudit($userId, 'SUBMIT_REVIEW', "Customer submitted {$rating}-star review for booking #{$bookingId}", 'reviews');
            setFlash('success', '✓ Thank you for your feedback! Your review has been submitted.');
            redirect(APP_URL . '/customer/bookings.php');
        } catch (Exception $e) {
            $errors[] = 'Failed to submit review: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/navbar.php';
?>

<style>
.star-rating { display:flex; gap:4px; flex-direction:row-reverse; justify-content:flex-end; }
.star-rating input { display:none; }
.star-rating label {
    font-size:2rem; color:rgba(255,255,255,0.2); cursor:pointer;
    transition:color 0.15s, transform 0.1s;
}
.star-rating label:hover,
.star-rating label:hover ~ label,
.star-rating input:checked ~ label {
    color:#f5a623; transform:scale(1.1);
}
.mini-star-rating { display:flex; gap:3px; flex-direction:row-reverse; justify-content:flex-end; }
.mini-star-rating input { display:none; }
.mini-star-rating label {
    font-size:1.3rem; color:rgba(255,255,255,0.2); cursor:pointer; transition:color 0.15s;
}
.mini-star-rating label:hover,
.mini-star-rating label:hover ~ label,
.mini-star-rating input:checked ~ label { color:#f5a623; }
</style>

<div class="page-header">
    <div>
        <h1><i class="fa-solid fa-star" style="color:var(--accent-gold);"></i> Leave a Review</h1>
        <p>Share your experience for <strong><?= htmlspecialchars($booking['package_name']) ?></strong></p>
    </div>
    <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-secondary">
        <i class="fa-solid fa-arrow-left"></i> Back
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <span class="alert-icon">✗</span>
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
</div>
<?php endif; ?>

<?php if ($existing): ?>
<div class="card" style="border:1px solid rgba(39,174,96,0.3);max-width:700px;margin:0 auto;">
    <div style="padding:30px;text-align:center;">
        <div style="font-size:3rem;margin-bottom:12px;">⭐</div>
        <h2 style="color:#27ae60;margin-bottom:8px;">Review Already Submitted!</h2>
        <p style="color:var(--text-secondary);">You already left a review for this booking on <?= date('F j, Y', strtotime($existing['created_at'])) ?>.</p>
        <div style="font-size:2rem;color:#f5a623;margin:12px 0;">
            <?= str_repeat('★', (int)$existing['rating']) ?><?= str_repeat('☆', 5 - (int)$existing['rating']) ?>
        </div>
        <div style="background:var(--bg-card);border-radius:10px;padding:16px;margin:16px 0;font-style:italic;color:var(--text-secondary);">
            "<?= htmlspecialchars($existing['review_text']) ?>"
        </div>
        <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-primary">Back to My Bookings</a>
    </div>
</div>
<?php else: ?>

<div style="max-width:700px;margin:0 auto;">
    <!-- Booking Info Banner -->
    <div style="background:rgba(78,205,196,0.08);border:1px solid rgba(78,205,196,0.25);border-radius:12px;padding:16px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:center;">
        <div style="width:44px;height:44px;border-radius:50%;background:rgba(78,205,196,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-calendar-check" style="color:var(--accent-teal);"></i>
        </div>
        <div>
            <div style="font-weight:700;color:#fff;"><?= htmlspecialchars($booking['package_name']) ?></div>
            <div style="font-size:0.83rem;color:var(--text-secondary);">
                Booking <?= htmlspecialchars(getBookingRef($booking)) ?> • Event: <?= formatDate($booking['event_date']) ?> •
                <span style="color:#27ae60;font-weight:600;">✓ Completed</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-comment-dots" style="color:var(--accent-gold);"></i> Your Review</h2>
        </div>
        <form method="POST" style="padding:24px;" id="reviewForm">

            <!-- Overall Rating -->
            <div class="form-group" style="margin-bottom:24px;text-align:center;">
                <label class="form-label" style="font-size:1.05rem;font-weight:700;display:block;margin-bottom:12px;">
                    Overall Experience <span style="color:var(--accent-red);">*</span>
                </label>
                <div class="star-rating" style="justify-content:center;">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" <?= (($_POST['rating'] ?? 0) == $i) ? 'checked' : '' ?>>
                    <label for="star<?= $i ?>"><i class="fa-solid fa-star"></i></label>
                    <?php endfor; ?>
                </div>
                <div id="ratingLabel" style="margin-top:10px;font-size:0.88rem;color:var(--accent-gold);font-weight:600;height:20px;"></div>
            </div>

            <!-- Category Ratings -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px;">
                <?php
                $cats = [
                    ['key'=>'service_rating','label'=>'Service Quality','icon'=>'fa-handshake'],
                    ['key'=>'venue_rating','label'=>'Venue & Ambiance','icon'=>'fa-building'],
                    ['key'=>'food_rating','label'=>'Food & Catering','icon'=>'fa-utensils'],
                ];
                foreach ($cats as $cat):
                ?>
                <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:10px;padding:14px;text-align:center;">
                    <div style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:8px;">
                        <i class="fa-solid <?= $cat['icon'] ?>"></i> <?= $cat['label'] ?>
                    </div>
                    <div class="mini-star-rating" style="justify-content:center;">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" id="<?= $cat['key'] ?>_<?= $i ?>" name="<?= $cat['key'] ?>" value="<?= $i ?>">
                        <label for="<?= $cat['key'] ?>_<?= $i ?>"><i class="fa-solid fa-star"></i></label>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Review Text -->
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">
                    Your Review <span style="color:var(--accent-red);">*</span>
                    <span id="charCount" style="float:right;font-size:0.78rem;color:var(--text-muted);">0 / 1000</span>
                </label>
                <textarea name="review_text" id="reviewText" class="form-input" rows="5" required maxlength="1000"
                          placeholder="Share your experience — what did you love? How was the team? Would you recommend CraftHub to friends and family?"
                          style="resize:vertical;"><?= htmlspecialchars($_POST['review_text'] ?? '') ?></textarea>
                <small style="color:var(--text-muted);font-size:0.78rem;">Minimum 20 characters.</small>
            </div>

            <!-- Would Recommend -->
            <div style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:rgba(39,174,96,0.07);border:1px solid rgba(39,174,96,0.2);border-radius:10px;margin-bottom:20px;">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;width:100%;">
                    <input type="checkbox" name="would_recommend" id="wouldRecommend" value="1" checked
                           style="width:18px;height:18px;accent-color:#27ae60;">
                    <div>
                        <div style="font-weight:700;font-size:0.92rem;">👍 I would recommend CraftHub to others</div>
                        <div style="font-size:0.78rem;color:var(--text-muted);">Check this if you'd suggest our services to friends and family</div>
                    </div>
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;font-size:1rem;padding:14px;" id="submitReviewBtn">
                <i class="fa-solid fa-paper-plane"></i> Submit Review
            </button>
        </form>
    </div>
</div>

<script>
const labels = ['','Terrible 😞','Poor 😕','Average 😐','Good 😊','Excellent! 🎉'];
document.querySelectorAll('.star-rating input').forEach(inp => {
    inp.addEventListener('change', function() {
        document.getElementById('ratingLabel').textContent = labels[this.value] || '';
    });
});

const reviewText = document.getElementById('reviewText');
const charCount  = document.getElementById('charCount');
reviewText.addEventListener('input', () => {
    charCount.textContent = reviewText.value.length + ' / 1000';
    charCount.style.color = reviewText.value.length < 20 ? 'var(--accent-red)' : 'var(--accent-teal)';
});

document.getElementById('reviewForm').addEventListener('submit', function() {
    const btn = document.getElementById('submitReviewBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
