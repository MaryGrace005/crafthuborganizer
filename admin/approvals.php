<?php
$pageTitle = 'Pending Approvals';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);

$db      = getDB();
$adminId = $_SESSION['user_id'] ?? 0;

// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid form token. Please try again.');
        redirect(APP_URL . '/admin/approvals.php');
    }

    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);

    // APPROVE
    if ($action === 'approve' && $uid > 0) {
        $pending = $db->prepare("SELECT * FROM users WHERE user_id = ? AND status IN ('pending_approval','inactive')");
        $pending->execute([$uid]);
        $target = $pending->fetch();

        if ($target) {
            $loginEmail  = sanitize($_POST['login_email']  ?? '');
            $tempPass    = sanitize($_POST['temp_password'] ?? '');

            if (!$loginEmail || !filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
                setFlash('error', 'Please enter a valid Gmail address for the customer to log in with.');
                redirect(APP_URL . '/admin/approvals.php');
            }
            if (strlen($tempPass) < 6) {
                setFlash('error', 'Temporary password must be at least 6 characters.');
                redirect(APP_URL . '/admin/approvals.php');
            }

            // Check login_email uniqueness (can't clash with another user's email or login_email)
            $chk = $db->prepare("SELECT user_id FROM users WHERE (email = ? OR login_email = ?) AND user_id != ?");
            $chk->execute([$loginEmail, $loginEmail, $uid]);
            if ($chk->fetch()) {
                setFlash('error', "The Gmail address <strong>{$loginEmail}</strong> is already in use by another account.");
                redirect(APP_URL . '/admin/approvals.php');
            }

            // Hash the new password, store login_email & temp_password, activate
            $idCode = generateAccountIdCode();
            $hash   = password_hash($tempPass, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users
                          SET status = 'active', id_code = ?,
                              login_email = ?, password = ?,
                              temp_password = ?
                          WHERE user_id = ?")
               ->execute([$idCode, $loginEmail, $hash, $tempPass, $uid]);

            logAudit($adminId, 'APPROVE_ACCOUNT', "Approved account #{$uid} ({$target['email']}) → {$idCode}, login: {$loginEmail}", 'users');
            setFlash('success', "✓ Account for {$target['name']} approved! Account ID: <strong>{$idCode}</strong>. Login Gmail: <strong>{$loginEmail}</strong>");
        } else {
            setFlash('error', 'Account not found or already processed.');
        }
        redirect(APP_URL . '/admin/approvals.php');
    }

    // REJECT
    if ($action === 'reject' && $uid > 0) {
        $reason = sanitize($_POST['rejection_reason'] ?? '');
        $target = $db->prepare("SELECT * FROM users WHERE user_id = ?");
        $target->execute([$uid]);
        $targetUser = $target->fetch();

        if ($targetUser) {
            $db->prepare("UPDATE users SET status = 'rejected', rejection_reason = ? WHERE user_id = ?")
               ->execute([$reason ?: null, $uid]);
            logAudit($adminId, 'REJECT_ACCOUNT', "Rejected account #{$uid} ({$targetUser['email']}). Reason: {$reason}", 'users');
            setFlash('info', "Account for {$targetUser['name']} has been rejected.");
        } else {
            setFlash('error', 'Account not found.');
        }
        redirect(APP_URL . '/admin/approvals.php');
    }
}

// ── Fetch pending accounts ───────────────────────────────────────────────────
$pendingUsers = $db->query("
    SELECT * FROM users
    WHERE status IN ('pending_approval','inactive')
    ORDER BY created_at ASC
")->fetchAll();

$pendingCount = count($pendingUsers);
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1><i class="fa-solid fa-user-clock" style="color:#f5a623;"></i> Pending Approvals</h1>
        <p>Review and approve or reject accounts awaiting activation.</p>
    </div>
    <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-secondary">
        <i class="fa-solid fa-users"></i> All Users
    </a>
</div>

<?php displayFlash(); ?>

<?php if ($pendingCount === 0): ?>
<div class="card" style="text-align:center;padding:60px 40px;">
    <div style="width:80px;height:80px;border-radius:50%;background:rgba(39,174,96,0.12);color:#27ae60;font-size:2.2rem;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
        <i class="fa-solid fa-circle-check"></i>
    </div>
    <h2 style="color:#fff;margin-bottom:8px;">All caught up!</h2>
    <p style="color:var(--text-secondary);">There are no accounts waiting for approval.</p>
</div>

<?php else: ?>

<!-- Summary banner -->
<div style="display:flex;align-items:center;gap:14px;padding:16px 22px;background:rgba(245,166,35,0.07);border:1px solid rgba(245,166,35,0.25);border-radius:16px;margin-bottom:24px;">
    <div style="width:44px;height:44px;border-radius:12px;background:rgba(245,166,35,0.15);color:#f5a623;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
        <i class="fa-solid fa-bell"></i>
    </div>
    <div>
        <div style="font-weight:700;color:#fff;font-size:1rem;"><?= $pendingCount ?> account<?= $pendingCount > 1 ? 's' : '' ?> pending review</div>
        <div style="font-size:0.85rem;color:rgba(255,255,255,0.5);">Approve to issue a unique TH-XXXXXX Account ID. Reject to decline with an optional reason.</div>
    </div>
</div>

<!-- Pending accounts list -->
<div style="display:flex;flex-direction:column;gap:16px;">
<?php foreach ($pendingUsers as $u):
    $eventInterest = $u['event_type_interest'] ?? null;
    $businessName  = $u['business_name'] ?? null;
    $guestCount    = $u['guest_count_est'] ?? null;
    $profileNotes  = $u['profile_notes'] ?? null;
    $hasProfile    = $eventInterest || $businessName || $guestCount || $profileNotes;
?>
<div class="card" style="border:1px solid rgba(245,166,35,0.15);background:rgba(22,22,38,0.9);">
    <div style="padding:24px 28px;">

        <!-- Top row: user identity + action buttons -->
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;">

            <!-- User info -->
            <div style="display:flex;align-items:center;gap:16px;min-width:0;">
                <div style="width:54px;height:54px;border-radius:16px;background:linear-gradient(135deg,rgba(78,205,196,0.2),rgba(168,85,247,0.2));border:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fff;font-weight:800;flex-shrink:0;">
                    <?= strtoupper(substr($u['name'], 0, 1)) ?>
                </div>
                <div style="min-width:0;">
                    <div style="font-weight:800;font-size:1.05rem;color:#fff;margin-bottom:2px;"><?= htmlspecialchars($u['name']) ?></div>
                    <div style="font-size:0.85rem;color:rgba(255,255,255,0.5);">
                        <i class="fa-solid fa-envelope" style="width:14px;"></i> <?= htmlspecialchars($u['email']) ?>
                    </div>
                    <?php if (!empty($u['contact_no'])): ?>
                    <div style="font-size:0.82rem;color:rgba(255,255,255,0.4);">
                        <i class="fa-solid fa-phone" style="width:14px;"></i> <?= htmlspecialchars($u['contact_no']) ?>
                    </div>
                    <?php endif; ?>
                    <div style="font-size:0.78rem;color:rgba(255,255,255,0.3);margin-top:4px;">
                        <i class="fa-solid fa-calendar" style="width:14px;"></i> Signed up <?= formatDate($u['created_at']) ?>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div style="display:flex;gap:10px;align-items:center;flex-shrink:0;">
                <!-- Approve — opens credentials modal -->
                <button type="button" class="btn btn-success"
                        onclick="document.getElementById('approveModal-<?= $u['user_id'] ?>').style.display='flex'"
                        style="height:42px;font-weight:700;gap:8px;">
                    <i class="fa-solid fa-check"></i> Approve &amp; Activate
                </button>

                <!-- Reject trigger -->
                <button class="btn btn-danger" style="height:42px;font-weight:700;gap:8px;"
                        onclick="document.getElementById('rejectModal-<?= $u['user_id'] ?>').style.display='flex'">
                    <i class="fa-solid fa-xmark"></i> Reject
                </button>
            </div>
        </div>

        <?php if ($hasProfile): ?>
        <!-- Profile details -->
        <div style="margin-top:20px;padding:16px 20px;background:rgba(255,255,255,0.03);border-radius:14px;border:1px solid rgba(255,255,255,0.06);">
            <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.06em;color:rgba(255,255,255,0.4);font-weight:600;margin-bottom:14px;">
                <i class="fa-solid fa-clipboard-list" style="margin-right:6px;"></i>Submitted Profile
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;">
                <?php if ($businessName): ?>
                <div>
                    <div style="font-size:0.75rem;color:rgba(255,255,255,0.4);margin-bottom:3px;">Business / Company</div>
                    <div style="font-weight:600;color:#e0e0ff;font-size:0.9rem;"><?= htmlspecialchars($businessName) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($eventInterest): ?>
                <div>
                    <div style="font-size:0.75rem;color:rgba(255,255,255,0.4);margin-bottom:3px;">Event Type</div>
                    <div style="font-weight:600;color:#4ecdc4;font-size:0.9rem;"><?= htmlspecialchars($eventInterest) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($guestCount): ?>
                <div>
                    <div style="font-size:0.75rem;color:rgba(255,255,255,0.4);margin-bottom:3px;">Est. Guests</div>
                    <div style="font-weight:600;color:#f5a623;font-size:0.9rem;"><?= number_format($guestCount) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($profileNotes): ?>
            <div style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.06);">
                <div style="font-size:0.75rem;color:rgba(255,255,255,0.4);margin-bottom:4px;">Notes / Special Requests</div>
                <p style="color:rgba(255,255,255,0.65);font-size:0.88rem;line-height:1.6;margin:0;"><?= htmlspecialchars($profileNotes) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="margin-top:14px;padding:10px 16px;background:rgba(255,255,255,0.02);border-radius:10px;border:1px solid rgba(255,255,255,0.05);font-size:0.83rem;color:rgba(255,255,255,0.3);">
            <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
            No profiling form submitted — account was created directly by admin or client skipped Step 2.
        </div>
        <?php endif; ?>

        <!-- ── OLD credentials panel replaced by approve modal below ── -->

    </div>
</div>

<!-- ══ APPROVE CREDENTIALS MODAL for user <?= $u['user_id'] ?> ══ -->
<div id="approveModal-<?= $u['user_id'] ?>" class="modal-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="max-width:500px;width:100%;background:#16162a;border-radius:20px;border:1px solid rgba(78,205,196,0.25);padding:32px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;">
            <h3 style="font-family:'Outfit',sans-serif;font-size:1.15rem;font-weight:800;color:#fff;margin:0;display:flex;align-items:center;gap:10px;">
                <span style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#4ecdc4,#2da99e);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-key" style="color:#fff;font-size:0.85rem;"></i>
                </span>
                Set Login Credentials
            </h3>
            <button onclick="document.getElementById('approveModal-<?= $u['user_id'] ?>').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.5);font-size:1.4rem;cursor:pointer;line-height:1;">×</button>
        </div>

        <p style="color:rgba(255,255,255,0.55);font-size:0.88rem;margin-bottom:22px;line-height:1.6;">
            Approving <strong style="color:#fff;"><?= htmlspecialchars($u['name']) ?></strong>.
            Assign a <strong style="color:#4ecdc4;">Gmail address</strong> and a
            <strong style="color:#a855f7;">temporary password</strong> the customer will use to sign in.
        </p>

        <?php
        // Auto-generate default login email: last name + first name @crafthub.com
        $fn = $u['first_name'] ?? '';
        $sn = $u['surname'] ?? '';
        if (!$fn && !$sn && !empty($u['name'])) {
            $parts = array_values(array_filter(explode(' ', trim($u['name']))));
            if (count($parts) >= 2) {
                $sn = array_pop($parts);
                $fn = implode('', $parts);
            } else {
                $fn = $parts[0] ?? 'user';
                $sn = '';
            }
        }
        $fnClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $fn));
        $snClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $sn));
        $defaultLoginEmail = ($snClean || $fnClean) ? ($snClean . $fnClean . '@crafthub.com') : ($u['email'] ?? '');
        ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <input type="hidden" name="action"  value="approve">
            <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">

            <!-- Gmail -->
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" style="color:#4ecdc4;font-weight:700;font-size:0.82rem;text-transform:uppercase;letter-spacing:0.05em;">
                    <i class="fa-brands fa-google"></i> Gmail Address <span style="color:#e94560;">*</span>
                </label>
                <input type="email" name="login_email"
                       id="approveGmail-<?= $u['user_id'] ?>"
                       class="form-control"
                       value="<?= htmlspecialchars($u['login_email'] ?: $defaultLoginEmail) ?>"
                       placeholder="e.g. <?= htmlspecialchars($defaultLoginEmail) ?>"
                       required
                       style="border-color:rgba(78,205,196,0.35);">
                <div style="font-size:0.76rem;color:rgba(255,255,255,0.4);margin-top:5px;">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color:#4ecdc4;"></i>
                    Auto-generated as <strong><?= htmlspecialchars($defaultLoginEmail) ?></strong> (Last Name + First Name @crafthub.com).
                </div>
            </div>

            <!-- Temp password -->
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" style="color:#a855f7;font-weight:700;font-size:0.82rem;text-transform:uppercase;letter-spacing:0.05em;">
                    <i class="fa-solid fa-lock"></i> Temporary Password <span style="color:#e94560;">*</span>
                </label>
                <div style="display:flex;gap:8px;">
                    <input type="text" name="temp_password"
                           id="approvePwd-<?= $u['user_id'] ?>"
                           class="form-control"
                           placeholder="e.g. <?= ucfirst(strtolower(explode(' ', $u['name'])[0] ?? 'Customer')) ?>2024"
                           required minlength="6"
                           style="border-color:rgba(168,85,247,0.4);font-family:monospace;font-weight:700;font-size:1rem;letter-spacing:0.04em;">
                    <button type="button"
                            onclick="(function(el){el.value=Math.random().toString(36).slice(2,10)+'!'})(document.getElementById('approvePwd-<?= $u['user_id'] ?>'));"
                            title="Generate random password"
                            style="flex-shrink:0;background:rgba(168,85,247,0.15);border:1px solid rgba(168,85,247,0.35);color:#a855f7;border-radius:10px;padding:0 14px;font-size:0.85rem;cursor:pointer;white-space:nowrap;font-weight:600;transition:all 0.2s;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate
                    </button>
                </div>
                <div style="font-size:0.76rem;color:rgba(255,255,255,0.4);margin-top:5px;">
                    <i class="fa-solid fa-triangle-exclamation" style="color:#f5a623;"></i>
                    <strong style="color:#f5a623;">Note:</strong> Share this with the customer before approving. It will be visible in their account details.
                </div>
            </div>

            <!-- Warning note -->
            <div style="background:rgba(78,205,196,0.06);border:1px solid rgba(78,205,196,0.2);border-radius:12px;padding:12px 16px;margin-bottom:20px;font-size:0.82rem;color:rgba(255,255,255,0.55);">
                <i class="fa-solid fa-shield-check" style="color:#4ecdc4;margin-right:6px;"></i>
                A unique <strong style="color:#4ecdc4;">Account ID (TH-XXXXXX)</strong> will be auto-generated upon activation.
            </div>

            <div style="display:flex;gap:10px;">
                <button type="button" class="btn btn-secondary" style="flex:1;"
                        onclick="document.getElementById('approveModal-<?= $u['user_id'] ?>').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-success" style="flex:2;font-weight:700;font-size:0.95rem;">
                    <i class="fa-solid fa-check"></i> Confirm Approval
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal for this user -->
<div id="rejectModal-<?= $u['user_id'] ?>" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div class="modal" style="max-width:460px;width:100%;background:#16162a;border-radius:20px;border:1px solid rgba(233,69,96,0.2);padding:32px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Outfit',sans-serif;font-size:1.2rem;font-weight:700;color:#fff;margin:0;">
                <i class="fa-solid fa-ban" style="color:#e94560;margin-right:8px;"></i> Reject Account
            </h3>
            <button onclick="document.getElementById('rejectModal-<?= $u['user_id'] ?>').style.display='none'"
                    style="background:none;border:none;color:rgba(255,255,255,0.5);font-size:1.4rem;cursor:pointer;">×</button>
        </div>
        <p style="color:rgba(255,255,255,0.6);font-size:0.9rem;margin-bottom:20px;">
            You are about to reject the account for <strong style="color:#fff;"><?= htmlspecialchars($u['name']) ?></strong>.
            The client will see this reason on their pending page.
        </p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
            <input type="hidden" name="action"  value="reject">
            <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
            <div class="form-group">
                <label class="form-label">Rejection Reason <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                <textarea name="rejection_reason" class="form-control" rows="3"
                          placeholder="e.g. Incomplete information, outside service area, duplicate account…"></textarea>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button type="button" class="btn btn-secondary" style="flex:1;"
                        onclick="document.getElementById('rejectModal-<?= $u['user_id'] ?>').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn btn-danger" style="flex:1;font-weight:700;">
                    <i class="fa-solid fa-ban"></i> Confirm Reject
                </button>
            </div>
        </form>
    </div>
</div>

<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function togglePwd(userId, btn) {
    const el  = document.getElementById('pwd-val-' + userId);
    const eye = document.getElementById('eye-' + userId);
    if (!el) return;
    const hidden = el.style.filter !== 'none' && el.style.filter !== '';
    if (hidden) {
        el.style.filter        = 'none';
        el.style.userSelect    = 'text';
        eye.className          = 'fa-solid fa-eye-slash';
        btn.title              = 'Hide password';
    } else {
        el.style.filter        = 'blur(4px)';
        el.style.userSelect    = 'none';
        eye.className          = 'fa-solid fa-eye';
        btn.title              = 'Show password';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

