<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);
requireApproved();

$user   = getCurrentUser();
$db     = getDB();
$errors = [];

$userFirstName  = $user['first_name'] ?? '';
$userMiddleName = $user['middle_name'] ?? '';
$userSurname    = $user['surname'] ?? '';
$userGender     = $user['gender'] ?? '';

if (empty($userFirstName) && !empty($user['name'])) {
    $parts = explode(' ', trim($user['name']));
    if (count($parts) === 1) {
        $userFirstName = $parts[0];
    } else if (count($parts) === 2) {
        $userFirstName = $parts[0];
        $userSurname   = $parts[1];
    } else {
        $userFirstName  = $parts[0];
        $userMiddleName = implode(' ', array_slice($parts, 1, -1));
        $userSurname    = end($parts);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $surname    = sanitize($_POST['surname']     ?? '');
        $gender     = sanitize($_POST['gender']      ?? '');
        $phone      = sanitize($_POST['phone']       ?? '');
        $address    = sanitize($_POST['address']     ?? '');

        if (empty($firstName)) {
            $errors[] = 'First name is required.';
        }
        if (empty($surname)) {
            $errors[] = 'Surname is required.';
        }

        if (empty($errors)) {
            $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $surname])));
            $userId   = $user['user_id'] ?? $user['id'];
            $stmt     = $db->prepare("UPDATE users SET name = ?, first_name = ?, middle_name = ?, surname = ?, gender = ?, contact_no = ?, address = ? WHERE user_id = ?");
            $stmt->execute([$fullName, $firstName, $middleName, $surname, $gender, $phone, $address, $userId]);
            $_SESSION['user_name'] = $fullName;
            logAudit($userId, 'UPDATE_PROFILE', 'Updated profile information', 'users');
            setFlash('success', 'Profile updated successfully!');
            redirect(APP_URL . '/staff/profile.php');
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($new) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        }
        if ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        if (empty($errors)) {
            $userId = $user['user_id'] ?? $user['id'];
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->execute([$hash, $userId]);
            logAudit($userId, 'CHANGE_PASSWORD', 'Password changed successfully', 'users');
            setFlash('success', 'Password changed successfully!');
            redirect(APP_URL . '/staff/profile.php');
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
            <div style="width:80px;height:80px;background:linear-gradient(135deg,#f5a623,#e94560);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:white;margin:0 auto 12px;">
                <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
            <div style="font-size:0.8rem;color:var(--text-muted);"><?= ucfirst($user['role']) ?> Account</div>
            <?php if (!empty($user['id_code'])): ?>
            <div style="display:inline-flex;align-items:center;gap:8px;margin-top:10px;padding:8px 16px;background:rgba(245,166,35,0.1);border:1px solid rgba(245,166,35,0.25);border-radius:20px;cursor:pointer;" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($user['id_code']) ?>').then(()=>this.querySelector('span').textContent='Copied!')" title="Click to copy">
                <i class="fa-solid fa-id-badge" style="color:#f5a623;"></i>
                <code style="font-weight:800;color:#f5a623;font-size:0.95rem;letter-spacing:0.06em;"><?= htmlspecialchars($user['id_code']) ?></code>
                <span style="font-size:0.72rem;color:rgba(255,255,255,0.4);">📍 Copy</span>
            </div>
            <?php endif; ?>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="update_profile">

            <div class="form-row" style="grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group">
                    <label class="form-label" for="first_name">First Name <span style="color:#e94560;">*</span></label>
                    <input type="text" id="first_name" name="first_name" class="form-control"
                           value="<?= htmlspecialchars($userFirstName) ?>" placeholder="First Name" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" class="form-control"
                           value="<?= htmlspecialchars($userMiddleName) ?>" placeholder="Optional">
                </div>
            </div>

            <div class="form-row" style="grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group">
                    <label class="form-label" for="surname">Surname <span style="color:#e94560;">*</span></label>
                    <input type="text" id="surname" name="surname" class="form-control"
                           value="<?= htmlspecialchars($userSurname) ?>" placeholder="Surname" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="gender">Gender</label>
                    <select id="gender" name="gender" class="form-control">
                        <option value="">— Select Gender —</option>
                        <option value="Male" <?= $userGender === 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= $userGender === 'Female' ? 'selected' : '' ?>>Female</option>
                        <option value="Prefer not to say" <?= $userGender === 'Prefer not to say' ? 'selected' : '' ?>>Prefer not to say</option>
                    </select>
                </div>
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
                       value="<?= htmlspecialchars($user['contact_no'] ?? $user['phone'] ?? '') ?>" placeholder="09XXXXXXXXX">
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
