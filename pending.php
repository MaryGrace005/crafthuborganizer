<?php
$pageTitle = 'Account Under Review';
require_once __DIR__ . '/includes/header.php';

// Must be logged in to see this page
requireLogin();

$user = getCurrentUser();

// If somehow approved and active, send to dashboard
if ($user && $user['status'] === 'active') {
    redirectByRole($_SESSION['user_role']);
}

$isRejected = $user && $user['status'] === 'rejected';
$isPending  = !$isRejected;
?>

<style>
.pending-page {
    min-height: 92vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    background: radial-gradient(ellipse at 50% 0%, rgba(78,205,196,0.07) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 100%, rgba(168,85,247,0.06) 0%, transparent 55%),
                #0f0f1a;
}

.pending-card {
    max-width: 620px;
    width: 100%;
    background: rgba(20,20,38,0.92);
    backdrop-filter: blur(20px);
    border-radius: 28px;
    border: 1px solid rgba(255,255,255,0.08);
    box-shadow: 0 32px 80px rgba(0,0,0,0.6);
    overflow: hidden;
}

.pending-header {
    padding: 44px 44px 32px;
    text-align: center;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.pending-icon-ring {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    margin: 0 auto 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.4rem;
    position: relative;
}

.pending-icon-ring.pending {
    background: radial-gradient(circle, rgba(245,166,35,0.15) 0%, rgba(245,166,35,0.05) 100%);
    border: 2px solid rgba(245,166,35,0.35);
    color: #f5a623;
    animation: pulse-ring 2.5s ease-in-out infinite;
}

.pending-icon-ring.rejected {
    background: radial-gradient(circle, rgba(233,69,96,0.15) 0%, rgba(233,69,96,0.05) 100%);
    border: 2px solid rgba(233,69,96,0.35);
    color: #e94560;
}

@keyframes pulse-ring {
    0%, 100% { box-shadow: 0 0 0 0 rgba(245,166,35,0.25); }
    50%       { box-shadow: 0 0 0 18px rgba(245,166,35,0); }
}

.pending-body {
    padding: 32px 44px 44px;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 18px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 16px;
}

.status-pill.pending  { background: rgba(245,166,35,0.12); color: #f5a623; border: 1px solid rgba(245,166,35,0.3); }
.status-pill.rejected { background: rgba(233,69,96,0.12);  color: #e94560; border: 1px solid rgba(233,69,96,0.3);  }

.info-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    background: rgba(255,255,255,0.03);
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.06);
    margin-bottom: 10px;
}

.info-row .info-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.steps-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 20px 0;
}

.step-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    border-radius: 12px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
}

.step-dot {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 800;
    flex-shrink: 0;
}
</style>

<div class="pending-page">
    <div class="pending-card">

        <div class="pending-header">
            <!-- Logo -->
            <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:32px;">
                <div style="width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#4ecdc4,#a855f7);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.1rem;">
                    <i class="fa-solid fa-palette"></i>
                </div>
                <div style="font-family:'Outfit',sans-serif;font-weight:800;font-size:1.3rem;background:linear-gradient(135deg,#fff,#4ecdc4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">
                    <?= APP_NAME ?>
                </div>
            </div>

            <div class="pending-icon-ring <?= $isRejected ? 'rejected' : 'pending' ?>">
                <i class="fa-solid <?= $isRejected ? 'fa-circle-xmark' : 'fa-hourglass-half' ?>"></i>
            </div>

            <div class="status-pill <?= $isRejected ? 'rejected' : 'pending' ?>">
                <i class="fa-solid <?= $isRejected ? 'fa-ban' : 'fa-clock' ?>"></i>
                <?= $isRejected ? 'Application Not Approved' : 'Under Review' ?>
            </div>

            <h1 style="font-family:'Outfit',sans-serif;font-size:1.7rem;font-weight:800;color:#fff;margin-bottom:10px;line-height:1.2;">
                <?= $isRejected ? 'Account Not Approved' : 'Your Account Is Under Review' ?>
            </h1>

            <p style="color:rgba(240,240,255,0.6);font-size:0.95rem;line-height:1.7;max-width:420px;margin:0 auto;">
                <?php if ($isRejected): ?>
                    Thank you for your interest in <?= APP_NAME ?>. Unfortunately, your account application was not approved at this time.
                <?php else: ?>
                    Thank you for signing up! Our admin team is reviewing your application. You'll gain full access once it's approved.
                <?php endif; ?>
            </p>
        </div>

        <div class="pending-body">

            <!-- Account info rows -->
            <div class="info-row">
                <div class="info-icon" style="background:rgba(78,205,196,0.12);color:#4ecdc4;">
                    <i class="fa-solid fa-user"></i>
                </div>
                <div>
                    <div style="font-size:0.75rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.05em;">Name</div>
                    <div style="font-weight:700;color:#fff;font-size:0.95rem;"><?= htmlspecialchars($user['name'] ?? $_SESSION['user_name'] ?? 'Unknown') ?></div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-icon" style="background:rgba(168,85,247,0.12);color:#a855f7;">
                    <i class="fa-solid fa-envelope"></i>
                </div>
                <div>
                    <div style="font-size:0.75rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.05em;">Email</div>
                    <div style="font-weight:700;color:#fff;font-size:0.95rem;"><?= htmlspecialchars($user['email'] ?? $_SESSION['user_email'] ?? '') ?></div>
                </div>
            </div>

            <?php if ($isRejected && !empty($user['rejection_reason'])): ?>
            <!-- Rejection reason -->
            <div style="margin-top:16px;padding:16px 20px;background:rgba(233,69,96,0.07);border:1px solid rgba(233,69,96,0.25);border-radius:14px;">
                <div style="display:flex;align-items:center;gap:8px;font-weight:700;color:#e94560;margin-bottom:8px;font-size:0.88rem;">
                    <i class="fa-solid fa-circle-exclamation"></i> Reason for Rejection
                </div>
                <p style="color:rgba(255,255,255,0.7);font-size:0.9rem;line-height:1.6;margin:0;">
                    <?= htmlspecialchars($user['rejection_reason']) ?>
                </p>
            </div>
            <?php endif; ?>

            <?php if ($isPending): ?>
            <!-- What happens next -->
            <div style="margin-top:20px;">
                <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.07em;color:rgba(255,255,255,0.4);margin-bottom:12px;font-weight:600;">What Happens Next</div>
                <div class="steps-list">
                    <div class="step-item">
                        <div class="step-dot" style="background:rgba(78,205,196,0.15);color:#4ecdc4;">1</div>
                        <div style="font-size:0.88rem;color:#e0e0ff;">Admin reviews your submitted profile</div>
                    </div>
                    <div class="step-item">
                        <div class="step-dot" style="background:rgba(245,166,35,0.15);color:#f5a623;">2</div>
                        <div style="font-size:0.88rem;color:#e0e0ff;">You receive a unique Account ID upon approval</div>
                    </div>
                    <div class="step-item">
                        <div class="step-dot" style="background:rgba(39,174,96,0.15);color:#27ae60;">3</div>
                        <div style="font-size:0.88rem;color:#e0e0ff;">Full access to browse packages and make bookings</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Contact & Logout -->
            <div style="display:flex;gap:12px;margin-top:28px;">
                <?php if ($isRejected): ?>
                <a href="<?= APP_URL ?>/landing.php" class="btn btn-secondary" style="flex:1;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:8px;">
                    <i class="fa-solid fa-house"></i> Go Home
                </a>
                <?php else: ?>
                <button onclick="window.location.reload()" class="btn btn-secondary" style="flex:1;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:8px;">
                    <i class="fa-solid fa-rotate"></i> Check Status
                </button>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/logout.php" class="btn btn-secondary" style="flex:1;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:8px;border-color:rgba(233,69,96,0.3);color:#e94560;">
                    <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                </a>
            </div>

            <p style="text-align:center;margin-top:18px;font-size:0.83rem;color:rgba(255,255,255,0.35);">
                Questions? Contact us at <a href="mailto:admin@crafthub.com" style="color:#4ecdc4;">admin@crafthub.com</a>
            </p>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
