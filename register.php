<?php
$pageTitle = 'Register';
require_once __DIR__ . '/includes/header.php';

if (isLoggedIn()) { redirectByRole($_SESSION['user_role']); }

$errors = [];
$values = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['name']    = sanitize($_POST['name']    ?? '');
    $values['email']   = sanitize($_POST['email']   ?? '');
    $values['phone']   = sanitize($_POST['phone']   ?? '');
    $password          = $_POST['password']          ?? '';
    $confirm           = $_POST['confirm_password']  ?? '';

    if (empty($values['name']))  $errors[] = 'Full name is required.';
    if (empty($values['email'])) $errors[] = 'Email is required.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
    if (strlen($password) < 6)   $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm)  $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $db   = getDB();
        $check = $db->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$values['email']]);
        if ($check->fetch()) {
            $errors[] = 'This email is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, ?, 'customer')");
            $stmt->execute([$values['name'], $values['email'], $hash, $values['phone']]);
            $newId = $db->lastInsertId();
            logAudit($newId, 'REGISTER', 'New customer registered: ' . $values['email'], 'users');
            setFlash('success', 'Account created! You can now log in.');
            redirect(APP_URL . '/login.php');
        }
    }
}
?>

<div class="auth-page">
    <div class="auth-container">

        <div class="auth-brand">
            <div class="auth-brand-logo">
                <div class="logo-icon"><i class="fa-solid fa-palette"></i></div>
                <div class="logo-text"><?= APP_NAME ?></div>
            </div>
            <h2>Join the CraftHub Community</h2>
            <p>Create your free account and start exploring amazing craft packages, workshops, and events.</p>
            <div class="auth-brand-features">
                <div class="auth-feature"><i class="fa-solid fa-star"></i> Access exclusive craft packages</div>
                <div class="auth-feature"><i class="fa-solid fa-clock"></i> Book events in minutes</div>
                <div class="auth-feature"><i class="fa-solid fa-bell"></i> Track your bookings easily</div>
                <div class="auth-feature"><i class="fa-solid fa-heart"></i> Join our growing community</div>
            </div>
        </div>

        <div class="auth-form-side">
            <h3>Create Account</h3>
            <p class="auth-subtitle">Sign up as a customer to get started</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <span class="alert-icon">✗</span>
                    <div>
                        <?php foreach ($errors as $e): ?>
                            <div><?= htmlspecialchars($e) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php displayFlash(); ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="name">Full Name</label>
                    <input type="text" id="name" name="name" class="form-control"
                           placeholder="Your full name"
                           value="<?= htmlspecialchars($values['name'] ?? '') ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="you@example.com"
                           value="<?= htmlspecialchars($values['email'] ?? '') ?>"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number <span style="color:var(--text-muted)">(optional)</span></label>
                    <input type="tel" id="phone" name="phone" class="form-control"
                           placeholder="09XXXXXXXXX"
                           value="<?= htmlspecialchars($values['phone'] ?? '') ?>">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control"
                               placeholder="Min. 6 characters" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                               placeholder="Repeat password" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:4px;">
                    <i class="fa-solid fa-user-plus"></i>
                    Create Account
                </button>
            </form>

            <p class="text-center mt-3" style="color:var(--text-secondary);font-size:0.9rem;">
                Already have an account?
                <a href="<?= APP_URL ?>/login.php" class="auth-link">Sign in</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
