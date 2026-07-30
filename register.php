<?php
$pageTitle = 'Account Required';
require_once __DIR__ . '/includes/header.php';

// If already logged in, redirect
if (isLoggedIn()) { redirectByRole($_SESSION['user_role']); }
?>

<div class="auth-page" style="min-height:92vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;background:radial-gradient(circle at 50% 20%, rgba(78,205,196,0.08) 0%, rgba(15,15,26,1) 80%);">
    <div class="auth-container" style="max-width:960px;width:100%;display:grid;grid-template-columns:1fr 1.25fr;border-radius:24px;overflow:hidden;background:rgba(22,22,38,0.85);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.08);box-shadow:0 24px 60px rgba(0,0,0,0.5), 0 0 40px rgba(78,205,196,0.08);">

        <!-- Left Brand Side -->
        <div class="auth-brand" style="background:linear-gradient(135deg, #1a0d2e 0%, #2d0a1e 50%, #1a1a0d 100%);padding:44px 36px;display:flex;flex-direction:column;justify-content:space-between;position:relative;border-right:1px solid rgba(255,255,255,0.06);">
            <div>
                <div class="auth-brand-logo" style="display:flex;align-items:center;gap:12px;margin-bottom:32px;">
                    <div class="logo-icon" style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,var(--accent-teal),var(--accent-purple));display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;box-shadow:0 8px 20px rgba(78,205,196,0.3);">
                        <i class="fa-solid fa-palette"></i>
                    </div>
                    <div class="logo-text" style="font-family:'Outfit',sans-serif;font-weight:800;font-size:1.6rem;letter-spacing:-0.02em;background:linear-gradient(135deg,#fff,var(--accent-teal));-webkit-background-clip:text;-webkit-text-fill-color:transparent;">
                        <?= APP_NAME ?>
                    </div>
                </div>

                <h2 style="font-family:'Outfit',sans-serif;font-size:2rem;font-weight:800;line-height:1.25;margin-bottom:16px;color:#ffffff;letter-spacing:-0.02em;">
                    Account Access by <span style="background:linear-gradient(135deg,var(--accent-gold),#ff758c);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Admin Only</span>
                </h2>

                <p style="color:rgba(240,240,255,0.75);font-size:0.95rem;line-height:1.7;margin-bottom:32px;">
                    To protect customer privacy and manage event slots efficiently, customer accounts are created exclusively by our administrative team.
                </p>

                <div class="auth-brand-features" style="display:grid;gap:14px;">
                    <div class="auth-feature" style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(255,255,255,0.04);border-radius:12px;border:1px solid rgba(255,255,255,0.06);font-size:0.88rem;color:#e2e2fe;">
                        <i class="fa-solid fa-shield-halved" style="color:var(--accent-teal);font-size:1.1rem;width:20px;"></i>
                        Secure, admin-verified accounts
                    </div>
                    <div class="auth-feature" style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(255,255,255,0.04);border-radius:12px;border:1px solid rgba(255,255,255,0.06);font-size:0.88rem;color:#e2e2fe;">
                        <i class="fa-solid fa-fingerprint" style="color:var(--accent-gold);font-size:1.1rem;width:20px;"></i>
                        Strict 1-device IP access policy
                    </div>
                    <div class="auth-feature" style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:rgba(255,255,255,0.04);border-radius:12px;border:1px solid rgba(255,255,255,0.06);font-size:0.88rem;color:#e2e2fe;">
                        <i class="fa-solid fa-calendar-check" style="color:#a855f7;font-size:1.1rem;width:20px;"></i>
                        Guaranteed slot reservation
                    </div>
                </div>
            </div>

            <div style="margin-top:32px;padding-top:20px;border-top:1px solid rgba(255,255,255,0.08);font-size:0.82rem;color:rgba(255,255,255,0.5);">
                Need assistance? Contact our office team anytime.
            </div>
        </div>

        <!-- Right Content Side -->
        <div class="auth-form-side" style="padding:44px 40px;display:flex;flex-direction:column;justify-content:center;background:linear-gradient(135deg, #1a0d2e 0%, #2d0a1e 50%, #1a1a0d 100%);">
            
            <div style="text-align:center;margin-bottom:28px;">
                <div style="width:76px;height:76px;border-radius:50%;background:linear-gradient(135deg,rgba(78,205,196,0.15),rgba(168,85,247,0.15));border:1px solid rgba(78,205,196,0.3);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:2.2rem;color:var(--accent-teal);box-shadow:0 8px 24px rgba(0,0,0,0.3);">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <h3 style="font-family:'Outfit',sans-serif;font-size:1.6rem;font-weight:700;margin-bottom:6px;color:#fff;">Account Registration</h3>
                <p style="color:var(--text-secondary);font-size:0.92rem;">
                    Self-registration is <span style="color:#e94560;font-weight:700;background:rgba(233,69,96,0.12);padding:2px 8px;border-radius:6px;">not available</span> online.
                </p>
            </div>

            <!-- How to Get an Account Card -->
            <div style="background:rgba(78,205,196,0.05);border:1px solid rgba(78,205,196,0.2);border-radius:16px;padding:24px;margin-bottom:24px;">
                <div style="display:flex;align-items:center;gap:10px;font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;color:var(--accent-teal);margin-bottom:16px;">
                    <i class="fa-solid fa-circle-info" style="font-size:1.2rem;"></i> How to Get Your Customer Account
                </div>

                <div style="display:grid;gap:12px;">
                    <div style="display:flex;gap:14px;align-items:flex-start;">
                        <div style="width:26px;height:26px;border-radius:50%;background:var(--accent-teal);color:#0f0f1a;font-weight:800;font-size:0.82rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;">1</div>
                        <div style="font-size:0.88rem;color:#e0e0ff;line-height:1.5;">
                            <strong>Contact Us or Visit Office:</strong> Reach out via phone, email, or in person.
                        </div>
                    </div>
                    <div style="display:flex;gap:14px;align-items:flex-start;">
                        <div style="width:26px;height:26px;border-radius:50%;background:var(--accent-teal);color:#0f0f1a;font-weight:800;font-size:0.82rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;">2</div>
                        <div style="font-size:0.88rem;color:#e0e0ff;line-height:1.5;">
                            <strong>Submit Details:</strong> Provide your name, contact number, and valid ID.
                        </div>
                    </div>
                    <div style="display:flex;gap:14px;align-items:flex-start;">
                        <div style="width:26px;height:26px;border-radius:50%;background:var(--accent-teal);color:#0f0f1a;font-weight:800;font-size:0.82rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;">3</div>
                        <div style="font-size:0.88rem;color:#e0e0ff;line-height:1.5;">
                            <strong>Account Provisioning:</strong> Admin will issue your credentials and set initial password.
                        </div>
                    </div>
                    <div style="display:flex;gap:14px;align-items:flex-start;">
                        <div style="width:26px;height:26px;border-radius:50%;background:var(--accent-teal);color:#0f0f1a;font-weight:800;font-size:0.82rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;">4</div>
                        <div style="font-size:0.88rem;color:#e0e0ff;line-height:1.5;">
                            <strong>First Login:</strong> Log in from your primary device to register your device access.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Options Grid -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;">
                <div style="padding:14px 16px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;border-radius:10px;background:rgba(245,166,35,0.12);color:var(--accent-gold);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                        <i class="fa-solid fa-phone"></i>
                    </div>
                    <div>
                        <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">Call / SMS</div>
                        <div style="font-size:0.88rem;font-weight:700;color:#fff;">+63 900 000 0000</div>
                    </div>
                </div>

                <div style="padding:14px 16px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;border-radius:10px;background:rgba(78,205,196,0.12);color:var(--accent-teal);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                        <i class="fa-solid fa-envelope"></i>
                    </div>
                    <div>
                        <div style="font-size:0.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;">Email Support</div>
                        <div style="font-size:0.88rem;font-weight:700;color:#fff;">admin@crafthub.com</div>
                    </div>
                </div>
            </div>

            <a href="<?= APP_URL ?>/login.php" class="btn btn-primary btn-block btn-lg" style="height:50px;font-size:1rem;font-weight:700;border-radius:12px;display:flex;align-items:center;justify-content:center;gap:10px;background:linear-gradient(135deg,#e94560,#c0392b);box-shadow:0 8px 24px rgba(233,69,96,0.35);transition:all 0.3s ease;">
                <i class="fa-solid fa-right-to-bracket"></i> Proceed to Login Page
            </a>

            <p style="text-align:center;margin-top:20px;color:var(--text-secondary);font-size:0.88rem;">
                Already have an account? <a href="<?= APP_URL ?>/login.php" style="color:var(--accent-teal);font-weight:600;text-decoration:underline;">Sign in here</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
