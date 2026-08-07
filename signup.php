<?php
$pageTitle = 'Create Your Account';
require_once __DIR__ . '/includes/header.php';

// Already logged in → go to their destination
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user && $user['status'] === 'active') {
        redirectByRole($_SESSION['user_role']);
    } else {
        redirect(APP_URL . '/pending.php');
    }
}

$db     = getDB();
$step   = (int)($_SESSION['signup_step'] ?? 1);
$errors = [];

// ── Step 1 POST: create the user account ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '1') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $surname    = sanitize($_POST['surname']     ?? '');
        $name       = trim(implode(' ', array_filter([$firstName, $middleName, $surname])));
        $email      = sanitize($_POST['email']       ?? '');
        $phone      = sanitize($_POST['phone']       ?? '');
        $password   = $_POST['password']             ?? '';
        $confirm    = $_POST['confirm_password']     ?? '';

        if (!$firstName) $errors[]       = 'First name is required.';
        if (!$surname)   $errors[]       = 'Surname is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
        if (strlen($password) < 8)       $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)      $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            // Check email uniqueness
            $chk = $db->prepare("SELECT user_id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $errors[] = 'That email address is already registered. Please sign in or use a different email.';
            }
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (name, email, password, contact_no, role, status) VALUES (?, ?, ?, ?, 'customer', 'pending_approval')")
               ->execute([$name, $email, $hash, $phone]);
            $newUserId = (int)$db->lastInsertId();

            // Store in session for step 2
            $_SESSION['signup_user_id']   = $newUserId;
            $_SESSION['signup_user_name'] = $name;
            $_SESSION['signup_step']      = 2;

            // Log them in so session is available on pending.php
            loginUser([
                'user_id' => $newUserId,
                'id'      => $newUserId,
                'name'    => $name,
                'email'   => $email,
                'role'    => 'customer',
                'status'  => 'pending_approval',
            ]);

            logAudit($newUserId, 'SELF_SIGNUP', "New client self-registered: {$email}", 'users');
            redirect(APP_URL . '/signup.php?step=2');
        }
    }
}

// ── Step 2 POST: save profiling info then send to pending ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === '2') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $userId         = (int)($_SESSION['signup_user_id'] ?? $_SESSION['user_id'] ?? 0);
        $businessName   = sanitize($_POST['business_name']       ?? '');
        $eventInterest  = sanitize($_POST['event_type_interest'] ?? '');
        $guestCount     = (int)($_POST['guest_count_est']        ?? 0);
        $profileNotes   = sanitize($_POST['profile_notes']       ?? '');

        if ($userId > 0) {
            $db->prepare("UPDATE users SET business_name = ?, event_type_interest = ?, guest_count_est = ?, profile_notes = ? WHERE user_id = ?")
               ->execute([$businessName ?: null, $eventInterest ?: null, $guestCount ?: null, $profileNotes ?: null, $userId]);
            logAudit($userId, 'PROFILE_SUBMITTED', 'Client submitted profiling form', 'users');
        }

        unset($_SESSION['signup_step'], $_SESSION['signup_user_id'], $_SESSION['signup_user_name']);
        redirect(APP_URL . '/pending.php');
    }
}

// ── Determine which step to show ─────────────────────────────────────────────
$urlStep = (int)($_GET['step'] ?? 1);
if ($urlStep === 2 && isset($_SESSION['signup_user_id'])) {
    $step = 2;
} elseif ($urlStep === 2 && !isset($_SESSION['signup_user_id'])) {
    redirect(APP_URL . '/signup.php');
} else {
    $step = 1;
}
?>

<div class="auth-page" style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;background:radial-gradient(ellipse at 30% 10%, rgba(78,205,196,0.08) 0%, transparent 60%), radial-gradient(ellipse at 80% 80%, rgba(168,85,247,0.07) 0%, transparent 55%), #0f0f1a;">

    <div style="max-width:980px;width:100%;display:grid;grid-template-columns:1fr 1.4fr;border-radius:28px;overflow:hidden;background:rgba(20,20,38,0.9);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.08);box-shadow:0 32px 80px rgba(0,0,0,0.6),0 0 60px rgba(78,205,196,0.06);">

        <!-- ── LEFT BRAND ── -->
        <div style="background:linear-gradient(160deg,#12003a 0%,#1e0025 50%,#0a1a14 100%);padding:48px 38px;display:flex;flex-direction:column;justify-content:space-between;border-right:1px solid rgba(255,255,255,0.06);">
            <div>
                <!-- Logo -->
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:36px;">
                    <div style="width:50px;height:50px;border-radius:16px;background:linear-gradient(135deg,#4ecdc4,#a855f7);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;box-shadow:0 8px 24px rgba(78,205,196,0.35);">
                        <i class="fa-solid fa-palette"></i>
                    </div>
                    <div style="font-family:'Outfit',sans-serif;font-weight:800;font-size:1.5rem;background:linear-gradient(135deg,#fff,#4ecdc4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">
                        <?= APP_NAME ?>
                    </div>
                </div>

                <h2 style="font-family:'Outfit',sans-serif;font-size:1.9rem;font-weight:800;line-height:1.2;margin-bottom:14px;color:#fff;">
                    <?= $step === 1 ? 'Start Your<br><span style="background:linear-gradient(135deg,#4ecdc4,#a855f7);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Craft Journey</span>' : 'Tell Us About<br><span style="background:linear-gradient(135deg,#f5a623,#ff758c);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Your Event</span>' ?>
                </h2>

                <p style="color:rgba(240,240,255,0.7);font-size:0.93rem;line-height:1.7;margin-bottom:28px;">
                    <?= $step === 1
                        ? 'Create your account in two quick steps. Our team reviews every application to ensure a personalized, premium experience.'
                        : 'Share your event vision with us. This helps our team prepare a tailored proposal just for you.' ?>
                </p>

                <!-- Step indicators -->
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:14px;background:<?= $step >= 1 ? 'rgba(78,205,196,0.12)' : 'rgba(255,255,255,0.04)' ?>;border:1px solid <?= $step >= 1 ? 'rgba(78,205,196,0.3)' : 'rgba(255,255,255,0.06)' ?>;">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?= $step >= 1 ? 'linear-gradient(135deg,#4ecdc4,#2da99e)' : 'rgba(255,255,255,0.1)' ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.85rem;color:#fff;flex-shrink:0;">
                            <?= $step > 1 ? '<i class="fa-solid fa-check"></i>' : '1' ?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:0.9rem;color:<?= $step >= 1 ? '#4ecdc4' : 'rgba(255,255,255,0.5)' ?>;">Account Setup</div>
                            <div style="font-size:0.78rem;color:rgba(255,255,255,0.45);">Email, name & password</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:14px;background:<?= $step >= 2 ? 'rgba(245,166,35,0.12)' : 'rgba(255,255,255,0.04)' ?>;border:1px solid <?= $step >= 2 ? 'rgba(245,166,35,0.3)' : 'rgba(255,255,255,0.06)' ?>;">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?= $step >= 2 ? 'linear-gradient(135deg,#f5a623,#e8830c)' : 'rgba(255,255,255,0.1)' ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.85rem;color:#fff;flex-shrink:0;">2</div>
                        <div>
                            <div style="font-weight:700;font-size:0.9rem;color:<?= $step >= 2 ? '#f5a623' : 'rgba(255,255,255,0.5)' ?>;">Event Profile</div>
                            <div style="font-size:0.78rem;color:rgba(255,255,255,0.45);">Business & event details</div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:14px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.06);">
                        <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.85rem;color:rgba(255,255,255,0.4);flex-shrink:0;">
                            <i class="fa-solid fa-shield-check" style="font-size:0.8rem;"></i>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:0.9rem;color:rgba(255,255,255,0.4);">Admin Review</div>
                            <div style="font-size:0.78rem;color:rgba(255,255,255,0.3);">We'll verify & activate</div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top:32px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.07);font-size:0.81rem;color:rgba(255,255,255,0.4);">
                Already have an account? <a href="<?= APP_URL ?>/login.php" style="color:#4ecdc4;font-weight:600;">Sign in here</a>
            </div>
        </div>

        <!-- ── RIGHT FORM ── -->
        <div style="padding:48px 44px;display:flex;flex-direction:column;justify-content:center;">

            <?php if (!empty($errors)): ?>
            <div class="alert alert-error" style="margin-bottom:20px;">
                <span class="alert-icon">✗</span>
                <div><?php foreach ($errors as $e) echo '<div>' . htmlspecialchars($e) . '</div>'; ?></div>
            </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
            <!-- ══ STEP 1 FORM ══ -->
            <div style="margin-bottom:28px;">
                <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(78,205,196,0.1);border:1px solid rgba(78,205,196,0.25);border-radius:20px;padding:6px 14px;font-size:0.78rem;color:#4ecdc4;font-weight:600;letter-spacing:0.04em;margin-bottom:12px;">
                    <i class="fa-solid fa-user-plus"></i> STEP 1 OF 2
                </div>
                <h3 style="font-family:'Outfit',sans-serif;font-size:1.6rem;font-weight:800;color:#fff;margin-bottom:4px;">Create Your Account</h3>
                <p style="color:rgba(255,255,255,0.5);font-size:0.9rem;">Fill in your details to get started.</p>
            </div>

            <form method="POST" action="" data-validate>
                <input type="hidden" name="step" value="1">
                <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

                <div class="form-row" style="grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">First Name <span style="color:#e94560;">*</span></label>
                        <input type="text" name="first_name" id="signupFirstName" class="form-control" placeholder="Maria"
                               value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" placeholder="Optional"
                               value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Surname <span style="color:#e94560;">*</span></label>
                    <input type="text" name="surname" id="signupSurname" class="form-control" placeholder="Santos"
                           value="<?= htmlspecialchars($_POST['surname'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address <span style="color:#e94560;">*</span></label>
                    <input type="email" name="email" id="signupEmail" class="form-control" placeholder="mariasantos@crafthub.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email">
                    <div class="form-hint" style="color:#4ecdc4;margin-top:4px;font-size:0.78rem;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-fills as <strong>firstnamesurname@crafthub.com</strong>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" placeholder="09XXXXXXXXX"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>

                <div class="form-row" style="grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Password <span style="color:#e94560;">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Min. 8 characters" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm Password <span style="color:#e94560;">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Repeat password" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="height:52px;font-size:1rem;font-weight:700;border-radius:14px;margin-top:8px;background:linear-gradient(135deg,#4ecdc4,#2da99e);box-shadow:0 8px 24px rgba(78,205,196,0.35);border:none;">
                    <i class="fa-solid fa-arrow-right"></i> Continue to Event Profile
                </button>
            </form>

            <?php else: ?>
            <!-- ══ STEP 2 FORM ══ -->
            <div style="margin-bottom:28px;">
                <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(245,166,35,0.1);border:1px solid rgba(245,166,35,0.25);border-radius:20px;padding:6px 14px;font-size:0.78rem;color:#f5a623;font-weight:600;letter-spacing:0.04em;margin-bottom:12px;">
                    <i class="fa-solid fa-clipboard-list"></i> STEP 2 OF 2
                </div>
                <h3 style="font-family:'Outfit',sans-serif;font-size:1.6rem;font-weight:800;color:#fff;margin-bottom:4px;">Your Event Profile</h3>
                <p style="color:rgba(255,255,255,0.5);font-size:0.9rem;">All fields are optional — share as much or as little as you like.</p>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">

                <div class="form-group">
                    <label class="form-label">Business / Company Name</label>
                    <input type="text" name="business_name" class="form-control" placeholder="e.g. Santos Events Coordinator">
                    <div class="form-hint">Leave blank if you're booking personally.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Event Type of Interest</label>
                    <select name="event_type_interest" class="form-control">
                        <option value="">— Select an event type —</option>
                        <option value="Wedding">Wedding</option>
                        <option value="Birthday">Birthday</option>
                        <option value="Debut">Debut</option>
                        <option value="Christening">Christening</option>
                        <option value="Corporate">Corporate / Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Estimated Number of Guests</label>
                    <input type="number" name="guest_count_est" class="form-control" placeholder="e.g. 100" min="1" max="2000">
                </div>

                <div class="form-group">
                    <label class="form-label">Additional Notes / Special Requests</label>
                    <textarea name="profile_notes" class="form-control" rows="3"
                              placeholder="Any specific themes, dates in mind, dietary requirements, or questions for our team…"></textarea>
                </div>

                <div style="display:flex;gap:12px;margin-top:8px;">
                    <a href="<?= APP_URL ?>/pending.php" class="btn btn-secondary" style="flex:0 0 auto;height:52px;border-radius:14px;display:flex;align-items:center;gap:8px;">
                        <i class="fa-solid fa-forward-step"></i> Skip
                    </a>
                    <button type="submit" class="btn btn-primary" style="flex:1;height:52px;font-size:1rem;font-weight:700;border-radius:14px;background:linear-gradient(135deg,#f5a623,#e8830c);box-shadow:0 8px 24px rgba(245,166,35,0.35);border:none;">
                        <i class="fa-solid fa-paper-plane"></i> Submit Application
                    </button>
                </div>
            </form>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function() {
    const fnInput    = document.getElementById('signupFirstName');
    const snInput    = document.getElementById('signupSurname');
    const emailInput = document.getElementById('signupEmail');
    if (fnInput && snInput && emailInput) {
        let isCustom = false;
        emailInput.addEventListener('input', () => { isCustom = emailInput.value.trim() !== ''; });
        function genEmail() {
            if (isCustom) return;
            const fn = fnInput.value.trim().toLowerCase().replace(/[^a-z0-9]/g, '');
            const sn = snInput.value.trim().toLowerCase().replace(/[^a-z0-9]/g, '');
            if (fn || sn) {
                emailInput.value = fn + sn + '@crafthub.com';
            }
        }
        fnInput.addEventListener('input', genEmail);
        snInput.addEventListener('input', genEmail);
    }
})();
</script>
