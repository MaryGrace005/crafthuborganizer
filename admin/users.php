<?php
$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);

$db = getDB();
$adminId = $_SESSION['user_id'] ?? 0;

// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREATE a new customer account (admin only)
    if ($action === 'create') {
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $surname    = sanitize($_POST['surname']     ?? '');
        $name       = trim(implode(' ', array_filter([$firstName, $middleName, $surname])));
        $email    = sanitize($_POST['email']    ?? '');
        $phone    = sanitize($_POST['phone']    ?? '');
        $address  = sanitize($_POST['address']  ?? '');
        $tempPass = $_POST['password']          ?? '';
        $role     = in_array($_POST['role'] ?? '', ['customer','staff','cashier','admin']) ? $_POST['role'] : 'customer';
        $errors   = [];

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
            $db->prepare("INSERT INTO users (name, email, password, contact_no, address, role, status)
                          VALUES (?, ?, ?, ?, ?, ?, 'pending_approval')")
               ->execute([$name, $email, $hash, $phone, $address, $role]);
            $newId = (int)$db->lastInsertId();
            logAudit($adminId, 'CREATE_USER', "Admin created {$role} account for {$email} (pending approval)", 'users');
            setFlash('success', "Account for {$name} created and placed in Pending Approvals.");
        } else {
            setFlash('error', implode(' ', $errors));
        }
        redirect(APP_URL . '/admin/users.php');
    }

    // APPROVE customer account
    if ($action === 'approve') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $target = $db->prepare("SELECT * FROM users WHERE user_id = ? AND status IN ('pending_approval','inactive')");
        $target->execute([$uid]);
        $targetUser = $target->fetch();
        if ($targetUser) {
            $idCode = generateAccountIdCode();
            $db->prepare("UPDATE users SET status = 'active', id_code = ? WHERE user_id = ?")
               ->execute([$idCode, $uid]);
            logAudit($adminId, 'APPROVE_USER', "Admin approved account #{$uid} → {$idCode}", 'users');
            setFlash('success', "Account approved! Account ID: <strong>{$idCode}</strong>");
        } else {
            setFlash('error', 'Account not found or already approved.');
        }
        redirect(APP_URL . '/admin/users.php');
    }

    // RESET IP — allow a customer to log in from a new device
    if ($action === 'reset_ip') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $db->prepare("UPDATE users SET ip_address = NULL WHERE user_id = ?")->execute([$uid]);
        logAudit($adminId, 'RESET_IP', "Admin reset IP for user #{$uid}", 'users');
        setFlash('success', 'IP address reset. Customer may now log in from any device once.');
        redirect(APP_URL . '/admin/users.php');
    }

    // TOGGLE status
    if ($action === 'toggle_status') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $db->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE user_id = ?")->execute([$uid]);
        logAudit($adminId, 'TOGGLE_STATUS', "Admin toggled status for user #{$uid}", 'users');
        setFlash('success', 'User status updated.');
        redirect(APP_URL . '/admin/users.php');
    }

    // DELETE user
    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $db->prepare("DELETE FROM users WHERE user_id = ? AND user_id != ?")->execute([$uid, $adminId]);
        logAudit($adminId, 'DELETE_USER', "Admin deleted user #{$uid}", 'users');
        setFlash('success', 'User deleted.');
        redirect(APP_URL . '/admin/users.php');
    }
}

// ── Fetch Users ─────────────────────────────────────────────────────────────
$roleFilter = sanitize($_GET['role'] ?? 'all');
$allowed = ['all','pending','customer','staff','cashier','admin'];
if (!in_array($roleFilter, $allowed)) $roleFilter = 'all';

$pendingCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE status IN ('pending_approval','inactive')")->fetchColumn();

$sql = "SELECT * FROM users";
if ($roleFilter === 'pending') {
    $sql .= " WHERE status IN ('pending_approval','inactive')";
} elseif ($roleFilter !== 'all') {
    $sql .= " WHERE role = " . $db->quote($roleFilter);
}
$sql .= " ORDER BY (status = 'inactive') DESC, FIELD(role,'admin','staff','cashier','customer'), created_at DESC";
$users = $db->query($sql)->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Manage Users</h1>
        <p>Create and manage customer, staff, and admin accounts</p>
    </div>
    <button class="btn btn-primary" data-modal="createUserModal">
        <i class="fa-solid fa-user-plus"></i> Create Account
    </button>
</div>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <?php 
    $labels = [
        'all'      => 'All Users',
        'pending'  => 'Pending Approval' . ($pendingCount > 0 ? " ({$pendingCount})" : ''),
        'customer' => 'Customers',
        'staff'    => 'Staff',
        'cashier'  => 'Cashiers / Staff',
        'admin'    => 'Admins'
    ];
    foreach ($allowed as $r):
        $active = ($roleFilter === $r);
        $btnClass = $active ? 'btn-primary' : ($r === 'pending' && $pendingCount > 0 ? 'btn-warning' : 'btn-secondary');
    ?>
        <a href="?role=<?= $r ?>" class="btn btn-sm <?= $btnClass ?>">
            <?php if ($r === 'pending' && $pendingCount > 0): ?>
                <i class="fa-solid fa-bell"></i>
            <?php endif; ?>
            <?= $labels[$r] ?>
        </a>
    <?php endforeach; ?>
</div>

<?php displayFlash(); ?>

<div class="card">
    <!-- Live Search Bar -->
    <div style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.06);">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="position:relative;flex:1;min-width:220px;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:0.9rem;"></i>
                <input type="text" id="usersSearchInput"
                       placeholder="Search by surname, first name, email or Account ID..."
                       class="form-control" style="padding-left:40px;font-size:0.9rem;">
            </div>
            <span id="usersSearchCount" style="font-size:0.82rem;color:var(--text-muted);white-space:nowrap;"></span>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="table" id="usersTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Account ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="user-row" data-name="<?= strtolower(htmlspecialchars($u['name'])) ?>" data-email="<?= strtolower(htmlspecialchars($u['email'])) ?>" data-code="<?= strtolower(htmlspecialchars($u['id_code'] ?? '')) ?>" style="<?= in_array($u['status'], ['pending_approval','inactive']) ? 'background:rgba(245,166,35,0.04);' : '' ?>">
                    <td><?= $u['user_id'] ?></td>
                    <td>
                        <?php if (!empty($u['id_code'])): ?>
                            <code style="color:#4ecdc4;font-size:0.85rem;font-weight:700;background:rgba(78,205,196,0.1);padding:3px 8px;border-radius:6px;border:1px solid rgba(78,205,196,0.2);"><?= htmlspecialchars($u['id_code']) ?></code>
                        <?php else: ?>
                            <span style="color:rgba(255,255,255,0.2);font-size:0.8rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($u['name']) ?></div>
                        <?php if ($u['address']): ?>
                            <div style="font-size:0.75rem;color:var(--text-muted);"><?= htmlspecialchars($u['address']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['contact_no'] ?? '—') ?></td>
                    <td>
                        <?php
                        $roleColors = ['admin'=>'danger','staff'=>'warning','cashier'=>'warning','customer'=>'info'];
                        $rc = $roleColors[$u['role']] ?? 'secondary';
                        ?>
                        <span class="badge badge-<?= $rc ?>"><?= ucfirst($u['role']) ?></span>
                    </td>
                    <td>
                        <?php if (in_array($u['status'], ['pending_approval','inactive'])): ?>
                            <span class="badge badge-warning" style="display:inline-flex;align-items:center;gap:4px;background:rgba(245,166,35,0.18);color:#f5a623;border:1px solid rgba(245,166,35,0.35);">
                                <i class="fa-solid fa-clock"></i> Pending Approval
                            </span>
                        <?php elseif ($u['status'] === 'rejected'): ?>
                            <span class="badge badge-danger">Rejected</span>
                        <?php else: ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                <button type="submit" class="badge badge-success"
                                        style="border:none;cursor:pointer;background:none;padding:0;">
                                    <?= statusBadge('active') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.82rem;"><?= formatDate($u['created_at']) ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <?php if (in_array($u['status'], ['pending_approval','inactive'])): ?>
                                <a href="<?= APP_URL ?>/admin/approvals.php" class="btn btn-warning btn-sm" title="Go to Pending Approvals">
                                    <i class="fa-solid fa-user-clock"></i> Review
                                </a>
                            <?php endif; ?>

                            <?php if ($u['user_id'] !== (int)$adminId): ?>
                                <form method="POST" style="display:inline;margin:0;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"
                                            data-confirm="Delete account for <?= htmlspecialchars($u['name']) ?>? This cannot be undone.">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:0.78rem;">You</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create User Modal -->
<div class="modal-overlay" id="createUserModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fa-solid fa-user-plus"></i> Create New Account</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group" style="background:rgba(78,205,196,0.06);border:1px solid rgba(78,205,196,0.25);border-radius:12px;padding:14px;margin-bottom:18px;">
                    <label class="form-label" style="color:#4ecdc4;font-weight:700;margin-bottom:6px;">
                        <i class="fa-solid fa-fingerprint"></i> Auto-Generated Account ID
                    </label>
                    <input type="text" class="form-control" value="<?= getNextAccountIdCodePreview() ?> (Assigned on Approval)" disabled
                           style="background:rgba(15,15,26,0.7);color:#4ecdc4;font-weight:800;letter-spacing:0.04em;border:1px solid rgba(78,205,196,0.3);opacity:1;">
                    <div class="form-hint" style="color:rgba(255,255,255,0.6);margin-top:6px;font-size:0.78rem;">
                        <i class="fa-solid fa-shield-check" style="color:#27ae60;margin-right:4px;"></i>
                        Guaranteed strictly unique database ID code with zero duplicates (DB Unique Constraint).
                    </div>
                </div>
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label">First Name <span style="color:var(--accent-red);">*</span></label>
                        <input type="text" name="first_name" id="userFirstName" class="form-control" placeholder="e.g. Maria" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" placeholder="e.g. Cruz">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Surname <span style="color:var(--accent-red);">*</span></label>
                    <input type="text" name="surname" id="userSurname" class="form-control" placeholder="e.g. Santos" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address <span style="color:var(--accent-red);">*</span></label>
                    <input type="email" name="email" id="userEmail" class="form-control" placeholder="mariasantos@crafthub.com" required>
                    <div class="form-hint" style="color:#4ecdc4;margin-top:4px;font-size:0.78rem;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-fills as <strong>firstnamesurname@crafthub.com</strong>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" placeholder="09XXXXXXXXX">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <option value="customer" selected>Customer</option>
                            <option value="staff">Staff</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Home address">
                </div>
                <div class="form-group">
                    <label class="form-label">Temporary Password <span style="color:var(--accent-red);">*</span></label>
                    <input type="text" name="password" id="userPassword" class="form-control" placeholder="e.g. SantosMaria" required>
                    <div style="margin-top:6px;font-size:0.8rem;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <span style="color:#f5a623;font-weight:600;"><i class="fa-solid fa-lightbulb"></i> Suggested Password:</span>
                        <button type="button" id="btnUseSuggestPass" title="Click to fill password"
                                style="background:rgba(245,166,35,0.15);border:1px solid rgba(245,166,35,0.35);color:#f5a623;padding:3px 10px;border-radius:12px;font-weight:700;font-family:monospace;cursor:pointer;transition:all 0.2s;">
                            <i class="fa-solid fa-hand-pointer" style="font-size:0.75rem;"></i> <span id="passSuggestVal">SurnameFirstname</span>
                        </button>
                        <span id="passApplyFeedback" style="color:#27ae60;font-size:0.75rem;font-weight:700;display:none;">
                            <i class="fa-solid fa-check"></i> Applied!
                        </span>
                    </div>
                </div>

                <div style="background:rgba(233,69,96,0.07);border:1px solid rgba(233,69,96,0.2);border-radius:var(--radius-sm);padding:12px;font-size:0.82rem;color:var(--text-secondary);">
                    <i class="fa-solid fa-shield-halved" style="color:var(--accent-red);margin-right:6px;"></i>
                    <strong>IP Policy:</strong> The customer's device IP will be automatically recorded on their <em>first login</em>. They will only be able to log in from that device. Use the Reset IP button to allow a new device.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Create Account</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// Live surname/name search for users table & Auto email generator
(function() {
    const input   = document.getElementById('usersSearchInput');
    const countEl = document.getElementById('usersSearchCount');
    if (input) {
        function filterUsers() {
            const q = input.value.trim().toLowerCase();
            const rows = document.querySelectorAll('#usersTable .user-row');
            let visible = 0;
            rows.forEach(row => {
                const name  = row.dataset.name  || '';
                const email = row.dataset.email || '';
                const code  = row.dataset.code  || '';
                const text  = row.textContent.toLowerCase();
                const match = !q || name.includes(q) || email.includes(q) || code.includes(q) || text.includes(q);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (q) {
                countEl.textContent = `${visible} user(s) found for "${input.value}"`;
            } else {
                countEl.textContent = `${rows.length} total users`;
            }
        }
        input.addEventListener('input', filterUsers);
        filterUsers();
    }

    // Auto-generate @crafthub.com email & interactive clickable temporary password
    const fnInput      = document.getElementById('userFirstName');
    const snInput      = document.getElementById('userSurname');
    const emailInput   = document.getElementById('userEmail');
    const passInput    = document.getElementById('userPassword');
    const suggestBtn   = document.getElementById('btnUseSuggestPass');
    const suggestVal   = document.getElementById('passSuggestVal');
    const feedbackSpan = document.getElementById('passApplyFeedback');

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
