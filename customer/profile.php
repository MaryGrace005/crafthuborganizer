<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
requireRole(['customer']);
requireApproved();

$user   = getCurrentUser();
$db     = getDB();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name    = sanitize($_POST['name']    ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $address = sanitize($_POST['address'] ?? '');

        if (empty($name)) $errors[] = 'Name is required.';

        if (empty($errors)) {
            $userId = $user['user_id'] ?? $user['id'];
            $stmt = $db->prepare("UPDATE users SET name = ?, contact_no = ?, address = ? WHERE user_id = ?");
            $stmt->execute([$name, $phone, $address, $userId]);
            $_SESSION['user_name'] = $name;
            logAudit($userId, 'UPDATE_PROFILE', 'Updated profile information', 'users');
            setFlash('success', 'Profile updated successfully!');
            redirect(APP_URL . '/customer/profile.php');
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) $errors[] = 'Current password is incorrect.';
        if (strlen($new) < 6) $errors[] = 'New password must be at least 6 characters.';
        if ($new !== $confirm) $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            $userId = $user['user_id'] ?? $user['id'];
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->execute([$hash, $userId]);
            logAudit($userId, 'CHANGE_PASSWORD', 'Password changed successfully', 'users');
            setFlash('success', 'Password changed successfully!');
            redirect(APP_URL . '/customer/profile.php');
        }
    }
}
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <h1>My Profile</h1>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <span class="alert-icon">✗</span>
        <div><?php foreach ($errors as $e) echo "<div>" . htmlspecialchars($e) . "</div>"; ?></div>
    </div>
<?php endif; ?>

<?php displayFlash(); ?>

<div class="grid-2" style="align-items:start;">
    <!-- Profile Info -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-user-pen"></i> Personal Information</h2>
        </div>

        <div style="text-align:center;margin-bottom:24px;">
            <div style="width:80px;height:80px;background:linear-gradient(135deg,var(--accent-teal),var(--accent-red));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:white;margin:0 auto 12px;">
                <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
            <div style="font-size:0.8rem;color:var(--text-muted);">Customer Account</div>
            <?php if (!empty($user['id_code'])): ?>
            <div style="display:inline-flex;align-items:center;gap:8px;margin-top:10px;padding:8px 16px;background:rgba(78,205,196,0.1);border:1px solid rgba(78,205,196,0.25);border-radius:20px;cursor:pointer;" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($user['id_code']) ?>').then(()=>this.querySelector('span').textContent='Copied!')" title="Click to copy">
                <i class="fa-solid fa-id-badge" style="color:#4ecdc4;"></i>
                <code style="font-weight:800;color:#4ecdc4;font-size:0.95rem;letter-spacing:0.06em;"><?= htmlspecialchars($user['id_code']) ?></code>
                <span style="font-size:0.72rem;color:rgba(255,255,255,0.4);">📍 Copy</span>
            </div>
            <?php endif; ?>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="update_profile">

            <div class="form-group">
                <label class="form-label" for="name">Full Name</label>
                <input type="text" id="name" name="name" class="form-control"
                       value="<?= htmlspecialchars($user['name']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="email_display">Email Address</label>
                <input type="email" id="email_display" class="form-control"
                       value="<?= htmlspecialchars($user['email']) ?>" disabled
                       style="opacity:0.6;cursor:not-allowed;">
                <div class="form-hint">Email cannot be changed. Contact admin.</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" class="form-control"
                       value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="09XXXXXXXXX">
            </div>

            <div class="form-group">
                <label class="form-label" for="address">Address</label>
                <textarea id="address" name="address" class="form-control" rows="3"
                          placeholder="Your address"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
            </div>

            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:16px;">
                <i class="fa-solid fa-clock"></i>
                Member since <?= formatDate($user['created_at']) ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
            </button>
        </form>
    </div>

    <!-- Change Password -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fa-solid fa-lock"></i> Change Password</h2>
        </div>

        <form method="POST" action="" data-validate>
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
                <label class="form-label" for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password"
                       class="form-control" placeholder="Your current password" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password"
                       class="form-control" placeholder="Min. 6 characters" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password"
                       class="form-control" placeholder="Repeat new password" required>
            </div>

            <button type="submit" class="btn btn-warning btn-block">
                <i class="fa-solid fa-key"></i> Update Password
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
