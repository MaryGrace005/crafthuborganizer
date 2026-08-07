<?php
$pageTitle = 'Register Customer Account';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier']);

$db = getDB();
$staffId = $_SESSION['user_id'] ?? 0;

// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_customer') {
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $surname    = sanitize($_POST['surname']     ?? '');
        $name       = trim(implode(' ', array_filter([$firstName, $middleName, $surname])));
        $email      = sanitize($_POST['email']    ?? '');
        $phone      = sanitize($_POST['phone']    ?? '');
        $address    = sanitize($_POST['address']  ?? '');
        $tempPass   = $_POST['password']          ?? '';
        $errors     = [];

        if (!$firstName) $errors[] = 'First name is required.';
        if (!$surname)   $errors[] = 'Surname is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (strlen($tempPass) < 6) $errors[] = 'Password must be at least 6 characters.';

        // Check email uniqueness
        $chk = $db->prepare("SELECT user_id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) $errors[] = 'That email is already registered.';

        if (empty($errors)) {
            $hash = password_hash($tempPass, PASSWORD_DEFAULT);
            // Staff creates customer account with status = 'inactive' (Pending Admin Approval)
            $db->prepare("INSERT INTO users (name, email, password, contact_no, address, role, status)
                          VALUES (?, ?, ?, ?, ?, 'customer', 'inactive')")
               ->execute([$name, $email, $hash, $phone, $address]);
            $newId = (int)$db->lastInsertId();

            logAudit($staffId, 'STAFF_CREATE_CUSTOMER', "Staff registered customer account for {$email} (Pending Admin Approval)", 'users');
            setFlash('success', "Customer account for {$name} created successfully! Account is pending Admin approval.");
        } else {
            setFlash('error', implode(' ', $errors));
        }
        redirect(APP_URL . '/staff/customers.php');
    }
}

// ── Fetch Customers ─────────────────────────────────────────────────────────
$customers = $db->query("
    SELECT * FROM users 
    WHERE role = 'customer' 
    ORDER BY (status = 'inactive') DESC, created_at DESC
")->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Register Customer Account</h1>
        <p>Staff registration for customer event booking access</p>
    </div>
    <button class="btn btn-primary" data-modal="createCustomerModal">
        <i class="fa-solid fa-user-plus"></i> Register New Customer
    </button>
</div>

<!-- Info Alert -->
<div style="background:rgba(245,166,35,0.08);border:1px solid rgba(245,166,35,0.25);border-radius:var(--radius-md);padding:16px;margin-bottom:24px;display:flex;align-items:center;gap:14px;color:var(--text-primary);">
    <i class="fa-solid fa-shield-halved" style="color:var(--accent-gold);font-size:1.6rem;flex-shrink:0;"></i>
    <div style="font-size:0.88rem;line-height:1.5;">
        <strong style="color:var(--accent-gold);">Account Approval Policy:</strong> Customer accounts registered by staff will be set to <span style="background:rgba(245,166,35,0.2);color:#f5a623;padding:2px 6px;border-radius:4px;font-weight:700;">Pending Approval</span>. The Administrator must approve the account before the customer can log in.
    </div>
</div>

<?php displayFlash(); ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-users"></i> Registered Customer Accounts</h2>
    </div>

    <div class="table-wrapper">
        <table class="table" id="customersTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer Name</th>
                    <th>Email Address</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Registered Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                <tr>
                    <td colspan="7" class="text-center" style="padding:40px;color:var(--text-muted);">
                        No customer accounts registered yet.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><?= $c['user_id'] ?></td>
                        <td>
                            <div style="font-weight:700;color:#fff;"><?= htmlspecialchars($c['name']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($c['email']) ?></td>
                        <td><?= htmlspecialchars($c['contact_no'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($c['address'] ?? '—') ?></td>
                        <td>
                            <?php if ($c['status'] === 'active'): ?>
                                <span class="badge badge-success" style="display:inline-flex;align-items:center;gap:5px;">
                                    <i class="fa-solid fa-circle-check"></i> Approved &amp; Active
                                </span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="display:inline-flex;align-items:center;gap:5px;background:rgba(245,166,35,0.18);color:#f5a623;border:1px solid rgba(245,166,35,0.35);">
                                    <i class="fa-solid fa-clock"></i> Pending Admin Approval
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.82rem;color:var(--text-secondary);"><?= formatDate($c['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Register Customer -->
<div class="modal-overlay" id="createCustomerModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-user-plus"></i> Register Customer Account</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_customer">
            <div class="modal-body">
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label">First Name <span style="color:var(--accent-red);">*</span></label>
                        <input type="text" name="first_name" id="custFirstName" class="form-control" placeholder="e.g. Maria" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" placeholder="e.g. Cruz">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Surname <span style="color:var(--accent-red);">*</span></label>
                    <input type="text" name="surname" id="custSurname" class="form-control" placeholder="e.g. Santos" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address <span style="color:var(--accent-red);">*</span></label>
                    <input type="email" name="email" id="custEmail" class="form-control" placeholder="mariasantos@crafthub.com" required>
                    <div class="form-hint" style="color:#4ecdc4;margin-top:4px;font-size:0.78rem;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-fills as <strong>firstnamesurname@crafthub.com</strong>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" placeholder="09XXXXXXXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Home address">
                </div>
                <div class="form-group">
                    <label class="form-label">Temporary Password <span style="color:var(--accent-red);">*</span></label>
                    <input type="text" name="password" id="custPassword" class="form-control" placeholder="e.g. SantosMaria" required>
                    <div style="margin-top:6px;font-size:0.8rem;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <span style="color:#f5a623;font-weight:600;"><i class="fa-solid fa-lightbulb"></i> Suggested Password:</span>
                        <button type="button" id="btnUseSuggestPassCust" title="Click to fill password"
                                style="background:rgba(245,166,35,0.15);border:1px solid rgba(245,166,35,0.35);color:#f5a623;padding:3px 10px;border-radius:12px;font-weight:700;font-family:monospace;cursor:pointer;transition:all 0.2s;">
                            <i class="fa-solid fa-hand-pointer" style="font-size:0.75rem;"></i> <span id="passSuggestValCust">SurnameFirstname</span>
                        </button>
                        <span id="passApplyFeedbackCust" style="color:#27ae60;font-size:0.75rem;font-weight:700;display:none;">
                            <i class="fa-solid fa-check"></i> Applied!
                        </span>
                    </div>
                </div>

                <div style="background:rgba(245,166,35,0.08);border:1px solid rgba(245,166,35,0.25);border-radius:var(--radius-sm);padding:12px;font-size:0.82rem;color:var(--text-secondary);">
                    <i class="fa-solid fa-shield-halved" style="color:var(--accent-gold);margin-right:6px;"></i>
                    <strong>Note:</strong> Upon creation, this account will be sent to the Administrator for approval before the customer can sign in.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Submit Customer Account</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
(function() {
    const fnInput      = document.getElementById('custFirstName');
    const snInput      = document.getElementById('custSurname');
    const emailInput   = document.getElementById('custEmail');
    const passInput    = document.getElementById('custPassword');
    const suggestBtn   = document.getElementById('btnUseSuggestPassCust');
    const suggestVal   = document.getElementById('passSuggestValCust');
    const feedbackSpan = document.getElementById('passApplyFeedbackCust');

    if (fnInput && snInput && emailInput) {
        let isCustomEmail = false;
        let isCustomPass  = false;
        emailInput.addEventListener('input', () => { isCustomEmail = emailInput.value.trim() !== ''; });
        if (passInput) {
            passInput.addEventListener('input', () => { isCustomPass = passInput.value.trim() !== ''; });
        }

        function genFields() {
            const rawFn = fnInput.value.trim();
            const rawSn = snInput.value.trim();
            const fnClean = rawFn.toLowerCase().replace(/[^a-z0-9]/g, '');
            const snClean = rawSn.toLowerCase().replace(/[^a-z0-9]/g, '');

            if (!isCustomEmail && (fnClean || snClean)) {
                emailInput.value = fnClean + snClean + '@crafthub.com';
            }

            const snCap = rawSn ? rawSn.charAt(0).toUpperCase() + rawSn.slice(1).toLowerCase().replace(/[^a-z0-9]/g, '') : 'Surname';
            const fnCap = rawFn ? rawFn.charAt(0).toUpperCase() + rawFn.slice(1).toLowerCase().replace(/[^a-z0-9]/g, '') : 'Firstname';
            const suggested = (rawSn || rawFn) ? (snCap + fnCap) : 'SurnameFirstname';

            if (suggestVal) suggestVal.textContent = suggested;

            if (passInput && !isCustomPass && (rawFn || rawSn)) {
                passInput.value = suggested;
            }
        }

        fnInput.addEventListener('input', genFields);
        snInput.addEventListener('input', genFields);

        if (suggestBtn && passInput) {
            suggestBtn.addEventListener('click', () => {
                passInput.value = suggestVal.textContent;
                isCustomPass = false;
                if (feedbackSpan) {
                    feedbackSpan.style.display = 'inline';
                    setTimeout(() => { feedbackSpan.style.display = 'none'; }, 1500);
                }
            });
        }
    }
})();
</script>
