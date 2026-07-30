<?php
$pageTitle = 'Forgot Password';
require_once __DIR__ . '/includes/header.php';

if (isLoggedIn()) { redirectByRole($_SESSION['user_role']); }

$message = '';
$type    = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $type    = 'error';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT user_id AS id, user_id, name FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            logAudit($user['user_id'], 'PASSWORD_RESET_REQUEST', 'Password reset requested for: ' . $email, 'users');
        }

        // Always show same message to prevent email enumeration
        $message = 'If that email exists in our system, you will receive a password reset link shortly. (Contact your admin to reset your password.)';
        $type    = 'success';
    }
}
?>

<div class="auth-page">
    <div class="auth-container" style="max-width:480px;grid-template-columns:1fr;">
        <div class="auth-form-side" style="padding:48px;">
            <div class="text-center mb-3">
                <div style="width:64px;height:64px;background:linear-gradient(135deg,var(--accent-red),var(--accent-gold));
                            border-radius:50%;display:flex;align-items:center;justify-content:center;
                            font-size:1.8rem;color:white;margin:0 auto 20px;">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <h3>Forgot Password?</h3>
                <p class="auth-subtitle" style="margin-top:8px;">
                    Enter your email and we'll help you reset your password.
                </p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?= $type ?>">
                    <span class="alert-icon"><?= $type === 'success' ? '✓' : '✗' ?></span>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="you@example.com" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fa-solid fa-paper-plane"></i>
                    Send Reset Link
                </button>
            </form>

            <p class="text-center mt-3" style="color:var(--text-secondary);font-size:0.9rem;">
                Remembered your password?
                <a href="<?= APP_URL ?>/login.php" class="auth-link">Back to login</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
