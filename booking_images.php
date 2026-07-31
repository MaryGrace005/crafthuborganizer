<?php
$pageTitle = 'Booking Event Images';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/images.php';

requireLogin();

$user = getCurrentUser();
$userId = $user['user_id'] ?? $user['id'];
$db = getDB();

$bookingId = (int)($_GET['booking_id'] ?? 0);

if (!$bookingId) {
    setFlash('error', 'No booking selected.');
    redirect(APP_URL . '/index.php');
}

// Fetch booking details
$stmt = $db->prepare("
    SELECT b.*, b.booking_id AS id, p.package_name, v.venue_name, u.name AS customer_name, u.email AS customer_email
    FROM bookings b
    JOIN packages p ON b.package_id = p.package_id
    LEFT JOIN venues v ON b.venue_id = v.venue_id
    JOIN users u ON b.customer_id = u.user_id
    WHERE b.booking_id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found.');
    redirect(APP_URL . '/index.php');
}

// Access check: Customer can only view their own bookings, cashier/admin can view any
if ($user['role'] === 'customer' && (int)$booking['customer_id'] !== (int)$userId) {
    setFlash('error', 'Access denied.');
    redirect(APP_URL . '/customer/bookings.php');
}

// Handle Image Upload POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    $imageType = sanitize($_POST['image_type'] ?? 'event_photo');
    $caption   = sanitize($_POST['caption']    ?? '');
    $isPublic  = isset($_POST['is_public']) ? 1 : ($user['role'] === 'customer' ? 1 : 0);

    if (isset($_FILES['image'])) {
        $result = uploadBookingImage($bookingId, $userId, $_FILES['image'], $imageType, $caption, $isPublic);
        if ($result['success']) {
            setFlash('success', 'Image uploaded successfully!');
        } else {
            setFlash('error', $result['error']);
        }
    } else {
        setFlash('error', 'Please choose an image file to upload.');
    }
    redirect(APP_URL . "/booking_images.php?booking_id={$bookingId}");
}

// Handle Delete Image POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_image') {
    $imageId = (int)($_POST['image_id'] ?? 0);
    $force   = in_array($user['role'], ['admin', 'staff', 'cashier']);
    $res     = deleteBookingImage($imageId, $userId, $force);

    if ($res['success']) {
        setFlash('success', 'Image deleted successfully.');
    } else {
        setFlash('error', $res['error']);
    }
    redirect(APP_URL . "/booking_images.php?booking_id={$bookingId}");
}

// Fetch Images for this Booking
$isPublicOnly = ($user['role'] === 'customer');
$images = getBookingImages($bookingId, $isPublicOnly);
?>

<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Event Booking Images</h1>
        <p>
            Booking: <strong style="color:var(--accent-teal);"><?= htmlspecialchars(getBookingRef($booking)) ?></strong>
            • <?= htmlspecialchars($booking['package_name']) ?> (<?= formatDate($booking['event_date']) ?>)
        </p>
    </div>
    <div style="display:flex;gap:10px;">
        <?php if ($user['role'] === 'admin'): ?>
            <a href="<?= APP_URL ?>/admin/bookings.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Bookings</a>
        <?php elseif ($user['role'] === 'staff' || $user['role'] === 'cashier'): ?>
            <a href="<?= APP_URL ?>/staff/payments.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to Payments</a>
        <?php else: ?>
            <a href="<?= APP_URL ?>/customer/bookings.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back to My Bookings</a>
        <?php endif; ?>

        <button class="btn btn-primary" data-modal="uploadImageModal">
            <i class="fa-solid fa-cloud-arrow-up"></i> Upload Photo
        </button>
    </div>
</div>

<!-- Category Stats Cards -->
<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(52,152,219,0.15);color:var(--accent-blue);"><i class="fa-solid fa-images"></i></div>
        <div class="stat-info">
            <div class="stat-value"><?= count($images) ?></div>
            <div class="stat-label">Total Images</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(39,174,96,0.15);color:#27ae60;"><i class="fa-solid fa-camera"></i></div>
        <div class="stat-info">
            <div class="stat-value">
                <?= count(array_filter($images, fn($img) => $img['image_type'] === 'event_photo')) ?>
            </div>
            <div class="stat-label">Event Photos</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(245,166,35,0.15);color:var(--accent-gold);"><i class="fa-solid fa-receipt"></i></div>
        <div class="stat-info">
            <div class="stat-value">
                <?= count(array_filter($images, fn($img) => $img['image_type'] === 'payment_proof')) ?>
            </div>
            <div class="stat-label">Payment Proofs</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:rgba(155,89,182,0.15);color:var(--accent-purple);"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
        <div class="stat-info">
            <div class="stat-value">
                <?= count(array_filter($images, fn($img) => in_array($img['image_type'], ['venue_photo','decoration_photo','contract','other']))) ?>
            </div>
            <div class="stat-label">Other Media</div>
        </div>
    </div>
</div>

<!-- Image Gallery Container -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-photo-film"></i> Booking Photo Gallery</h2>
    </div>

    <?php if (empty($images)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-image" style="font-size:3rem;color:var(--text-muted);margin-bottom:12px;"></i>
            <h3>No Event Images Uploaded</h3>
            <p>Upload event photos, decoration ideas, or payment proof screenshots for this booking.</p>
            <button class="btn btn-primary" data-modal="uploadImageModal">
                <i class="fa-solid fa-cloud-arrow-up"></i> Upload First Image
            </button>
        </div>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:16px;padding:10px 0;">
            <?php foreach ($images as $img): ?>
                <div style="border:1px solid var(--border-color);border-radius:var(--radius-md);overflow:hidden;background:var(--bg-card);display:flex;flex-direction:column;">
                    <div style="position:relative;height:180px;background:#000;overflow:hidden;">
                        <img src="<?= imageUrl($img['image_path']) ?>" alt="<?= htmlspecialchars($img['caption'] ?: $img['original_name']) ?>" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" onclick="openImagePreview('<?= imageUrl($img['image_path']) ?>', '<?= htmlspecialchars(addslashes($img['caption'] ?: $img['original_name'])) ?>')">
                        <span class="badge badge-info" style="position:absolute;top:8px;left:8px;font-size:0.7rem;text-transform:capitalize;">
                            <?= str_replace('_', ' ', $img['image_type']) ?>
                        </span>
                        <?php if ($img['is_public']): ?>
                            <span class="badge badge-success" style="position:absolute;top:8px;right:8px;font-size:0.7rem;">Public</span>
                        <?php endif; ?>
                    </div>
                    <div style="padding:12px;flex:1;display:flex;flex-direction:column;justify-content:space-between;">
                        <div>
                            <div style="font-weight:600;font-size:0.88rem;margin-bottom:4px;word-break:break-word;">
                                <?= htmlspecialchars($img['caption'] ?: $img['original_name']) ?>
                            </div>
                            <div style="font-size:0.75rem;color:var(--text-secondary);margin-bottom:8px;">
                                Uploaded by <strong><?= htmlspecialchars($img['uploader_name'] ?? 'User') ?></strong> • <?= formatDate($img['uploaded_at']) ?>
                            </div>
                        </div>

                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding-top:8px;border-top:1px solid var(--border-color);">
                            <a href="<?= imageUrl($img['image_path']) ?>" target="_blank" class="btn btn-secondary btn-sm">
                                <i class="fa-solid fa-expand"></i> View
                            </a>
                            <?php if ($user['role'] === 'admin' || (int)$img['uploaded_by'] === (int)$userId): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_image">
                                    <input type="hidden" name="image_id" value="<?= $img['image_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete this image?">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Upload Image Modal -->
<div class="modal-overlay" id="uploadImageModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Event Image</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_image">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select Image * <span style="font-weight:400;color:var(--text-muted);">(Max 5 MB, JPG/PNG/WebP)</span></label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Image Category *</label>
                    <select name="image_type" class="form-control" required>
                        <option value="event_photo">Event Photo</option>
                        <option value="payment_proof">Payment Proof / Receipt Screenshot</option>
                        <option value="venue_photo">Venue Inspection Photo</option>
                        <option value="decoration_photo">Decoration Reference Photo</option>
                        <option value="contract">Signed Contract Scan</option>
                        <option value="other">Other Attachment</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Caption / Note</label>
                    <input type="text" name="caption" class="form-control" placeholder="e.g., Table setup reference photo">
                </div>
                <?php if (in_array($user['role'], ['admin', 'staff', 'cashier'])): ?>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_public" id="is_public" value="1" checked>
                        <label for="is_public" style="margin:0;cursor:pointer;">Visible to Customer in their portal</label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Image Lightbox Modal -->
<div class="modal-overlay" id="lightboxModal">
    <div class="modal" style="max-width:800px;background:#111;color:#fff;">
        <div class="modal-header" style="border-bottom:1px solid #333;">
            <h2 class="modal-title" id="lightboxCaption" style="color:#fff;">Image Preview</h2>
            <button class="modal-close" style="color:#fff;">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;padding:15px;">
            <img id="lightboxImg" src="" alt="Preview" style="max-width:100%;max-height:70vh;border-radius:var(--radius-sm);">
        </div>
    </div>
</div>

<script>
function openImagePreview(url, caption) {
    document.getElementById('lightboxImg').src = url;
    document.getElementById('lightboxCaption').textContent = caption || 'Image Preview';
    const modal = document.getElementById('lightboxModal');
    if (modal) {
        modal.classList.add('active');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
