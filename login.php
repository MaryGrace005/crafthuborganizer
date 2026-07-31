<?php
$pageTitle = 'Login';
require_once __DIR__ . '/includes/header.php';

// Already logged in → redirect to dashboard
if (isLoggedIn()) {
    redirectByRole($_SESSION['user_role']);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT *, user_id AS id FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            // ── IP Enforcement for Customer Accounts ──────────────────────
            if ($user['role'] === 'customer') {
                $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR']
                    ?? $_SERVER['HTTP_CLIENT_IP']
                    ?? $_SERVER['REMOTE_ADDR']
                    ?? '0.0.0.0';
                // Take first IP if X-Forwarded-For has multiple
                $clientIp = trim(explode(',', $clientIp)[0]);

                $storedIp = $user['ip_address'] ?? null;

                if ($storedIp === null || $storedIp === '') {
                    // First login — record IP
                    $db->prepare("UPDATE users SET ip_address = ? WHERE user_id = ?")
                       ->execute([$clientIp, $user['user_id'] ?? $user['id']]);
                } elseif ($storedIp !== $clientIp) {
                    // IP mismatch — block login
                    $error = 'Login blocked: Access from this device is not authorized for your account. Please contact the admin.';
                    logAudit(0, 'IP_BLOCK', "IP mismatch for {$email}: stored={$storedIp}, attempt={$clientIp}", 'users');
                    goto end_login;
                }
            }
            // ─────────────────────────────────────────────────────────────

            loginUser($user);
            logAudit($user['user_id'] ?? $user['id'], 'LOGIN', 'User logged in successfully.', 'users');
            setFlash('success', 'Welcome back, ' . $user['name'] . '!');
            redirectByRole($user['role']);
        } else {
            $error = 'Invalid email or password. Please try again.';
            logAudit(0, 'FAILED_LOGIN', 'Failed login attempt for: ' . $email, 'users');
        }
    }
}
end_login:
?>

<div class="auth-page">
    <div class="auth-container">

        <!-- Brand Side -->
        <div class="auth-brand">
            <div class="auth-brand-logo">
                <div class="logo-icon"><i class="fa-solid fa-palette"></i></div>
                <div class="logo-text"><?= APP_NAME ?></div>
            </div>
            <h2>Your Creative Hub Awaits</h2>
            <p>Manage bookings, packages, venues, and payments — all in one beautifully crafted platform.</p>
            <div class="auth-brand-features">
                <div class="auth-feature"><i class="fa-solid fa-box-open"></i> Browse & book craft packages</div>
                <div class="auth-feature"><i class="fa-solid fa-calendar-check"></i> Track your event bookings</div>
                <div class="auth-feature"><i class="fa-solid fa-money-bill-wave"></i> Hassle-free payment management</div>
                <div class="auth-feature"><i class="fa-solid fa-shield-halved"></i> Secure multi-role access</div>
            </div>
        </div>

        <!-- Form Side -->
        <div class="auth-form-side">
            <h3>Welcome Back</h3>
            <p class="auth-subtitle">Sign in to your account to continue</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">✗</span>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php displayFlash(); ?>

            <form method="POST" action="" data-validate>
                <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="you@example.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           required autocomplete="email">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="Enter your password"
                           required autocomplete="current-password">
                </div>

                <div class="flex-between mb-2" style="font-size:0.88rem;">
                    <label style="display:flex;align-items:center;gap:8px;color:var(--text-secondary);cursor:pointer;">
                        <input type="checkbox" name="remember" style="accent-color:var(--accent-red);">
                        Remember me
                    </label>
                    <a href="<?= APP_URL ?>/forgot_password.php" class="auth-link">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    Sign In
                </button>
            </form>

            <p class="text-center mt-3" style="color:var(--text-secondary);font-size:0.9rem;">
                Don't have an account?
                <a href="<?= APP_URL ?>/register.php" class="auth-link">Create one</a>
            </p>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
