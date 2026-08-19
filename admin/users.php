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
        $tempPass = bin2hex(random_bytes(8));
        $role     = in_array($_POST['role'] ?? '', ['customer','staff','cashier','admin']) ? $_POST['role'] : 'customer';
        $errors   = [];

        if (!$firstName) $errors[] = 'First name is required.';
        if (!$surname)   $errors[] = 'Surname is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

        // Check email uniqueness
        $chk = $db->prepare("SELECT user_id FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetch()) $errors[] = 'That email is already registered.';

        if (empty($errors)) {
            $hash = password_hash($tempPass, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (name, email, password, temp_password, contact_no, address, role, status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_approval')")
               ->execute([$name, $email, $hash, $tempPass, $phone, $address, $role]);
            $newId = (int)$db->lastInsertId();
            logAudit($adminId, 'CREATE_USER', "Admin created {$role} account for {$email} (pending approval)", 'users');
            setFlash('success', "Account for {$name} created and placed in Pending Approvals. Review the credentials on the approvals page.");
        } else {
            setFlash('error', implode(' ', $errors));
        }
        redirect(APP_URL . '/admin/approvals.php');
    }

    // APPROVE customer account
    if ($action === 'approve') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $target = $db->prepare("SELECT * FROM users WHERE user_id = ? AND status IN ('pending_approval','inactive')");
        $target->execute([$uid]);
        $targetUser = $target->fetch();
        if ($targetUser) {
            $idCode = generateAccountIdCode();
            $db->prepare("UPDATE users SET status = 'active', id_code = ?, temp_password = NULL WHERE user_id = ?")
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
                <tr class="user-row"
                    data-name="<?= strtolower(htmlspecialchars($u['name'])) ?>"
                    data-email="<?= strtolower(htmlspecialchars($u['email'])) ?>"
                    data-code="<?= strtolower(htmlspecialchars($u['id_code'] ?? '')) ?>"
                    style="<?= in_array($u['status'], ['pending_approval','inactive']) ? 'background:rgba(245,166,35,0.04);' : '' ?>">
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
                                <button type="button" class="btn btn-info btn-sm btn-view-user"
                                    title="View account details"
                                    data-id="<?= $u['user_id'] ?>"
                                    data-idcode="<?= htmlspecialchars($u['id_code'] ?? '—') ?>"
                                    data-name="<?= htmlspecialchars($u['name']) ?>"
                                    data-email="<?= htmlspecialchars($u['email']) ?>"
                                    data-phone="<?= htmlspecialchars($u['contact_no'] ?? '—') ?>"
                                    data-role="<?= ucfirst($u['role']) ?>"
                                    data-status="<?= htmlspecialchars($u['status']) ?>"
                                    data-address="<?= htmlspecialchars($u['address'] ?? '—') ?>"
                                    data-business="<?= htmlspecialchars($u['business_name'] ?? '—') ?>"
                                    data-eventtype="<?= htmlspecialchars($u['event_type_interest'] ?? '—') ?>"
                                    data-guests="<?= htmlspecialchars($u['guest_count_est'] ?? '—') ?>"
                                    data-notes="<?= htmlspecialchars($u['profile_notes'] ?? '—') ?>"
                                    data-registered="<?= formatDate($u['created_at']) ?>"
                                    data-loginemail="<?= htmlspecialchars($u['login_email'] ?? '') ?>"
                                    data-temppwd="<?= htmlspecialchars($u['temp_password'] ?? '') ?>"
                                    data-gender="<?= htmlspecialchars($u['gender'] ?? '—') ?>">
                                    <i class="fa-solid fa-eye"></i> View
                                </button>
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
                    <input type="email" name="email" id="userEmail" class="form-control" placeholder="user@example.com" required>
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

<!-- ── View User Modal ───────────────────────────────────────────────────── -->
<div class="modal-overlay" id="viewUserModal" style="display:none;">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header" style="background:linear-gradient(135deg,rgba(78,205,196,0.12),rgba(78,205,196,0.04));border-bottom:1px solid rgba(78,205,196,0.2);">
            <h2 class="modal-title" style="display:flex;align-items:center;gap:10px;">
                <span style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#4ecdc4,#2da99e);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fa-solid fa-user" style="color:#fff;font-size:0.85rem;"></i>
                </span>
                <span>Account Details</span>
            </h2>
            <button class="modal-close" id="viewUserClose">&times;</button>
        </div>
        <div class="modal-body" style="padding:24px 28px;">
            <!-- ID Banner -->
            <div style="background:rgba(78,205,196,0.08);border:1px solid rgba(78,205,196,0.2);border-radius:12px;padding:14px 18px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <div>
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Account ID</div>
                    <div id="vw-idcode" style="font-family:monospace;font-size:1.1rem;font-weight:800;color:#4ecdc4;"></div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">User ID</div>
                    <div id="vw-uid" style="font-size:0.9rem;font-weight:700;color:rgba(255,255,255,0.6);"></div>
                </div>
            </div>

            <!-- Info Grid -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div style="grid-column:1/-1;">
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Full Name</div>
                    <div id="vw-name" style="font-size:1rem;font-weight:700;color:#fff;"></div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Email Address</div>
                    <div id="vw-email" style="font-size:0.88rem;color:#4ecdc4;word-break:break-all;"></div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Phone Number</div>
                    <div id="vw-phone" style="font-size:0.88rem;color:rgba(255,255,255,0.8);"></div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Gender</div>
                    <div id="vw-gender" style="font-size:0.88rem;color:rgba(255,255,255,0.8);"></div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Role</div>
                    <div id="vw-role" style="font-size:0.88rem;"></div>
                </div>
                <div>
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Status</div>
                    <div id="vw-status" style="font-size:0.88rem;"></div>
                </div>
                <div style="grid-column:1/-1;">
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Address</div>
                    <div id="vw-address" style="font-size:0.88rem;color:rgba(255,255,255,0.8);"></div>
                </div>
            </div>

            <!-- Event Profile Section -->
            <div style="margin-top:22px;padding-top:18px;border-top:1px solid rgba(255,255,255,0.07);">
                <div style="font-size:0.75rem;color:rgba(255,255,255,0.35);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:14px;display:flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-clipboard-list" style="color:#f5a623;"></i> Event Profile
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div>
                        <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Business / Company</div>
                        <div id="vw-business" style="font-size:0.88rem;color:rgba(255,255,255,0.8);"></div>
                    </div>
                    <div>
                        <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Event Type</div>
                        <div id="vw-eventtype" style="font-size:0.88rem;color:rgba(255,255,255,0.8);"></div>
                    </div>
                    <div>
                        <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Est. Guests</div>
                        <div id="vw-guests" style="font-size:0.88rem;color:rgba(255,255,255,0.8);"></div>
                    </div>
                    <div>
                        <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Registered</div>
                        <div id="vw-registered" style="font-size:0.88rem;color:rgba(255,255,255,0.8);"></div>
                    </div>
                    <div style="grid-column:1/-1;">
                        <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:4px;">Profile Notes</div>
                        <div id="vw-notes" style="font-size:0.88rem;color:rgba(255,255,255,0.8);white-space:pre-wrap;"></div>
                    </div>
                </div>
            </div>

            <!-- Login Credentials Section -->
            <div id="vw-creds-section" style="margin-top:22px;padding-top:18px;border-top:1px solid rgba(255,255,255,0.07);">
                <div style="font-size:0.75rem;color:rgba(255,255,255,0.35);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:14px;display:flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-key" style="color:#a855f7;"></i> Login Credentials
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <!-- Gmail -->
                    <div style="background:rgba(15,15,26,0.7);border:1px solid rgba(78,205,196,0.25);border-radius:10px;padding:12px 16px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.07em;color:rgba(255,255,255,0.35);font-weight:600;margin-bottom:6px;">
                            <i class="fa-brands fa-google" style="margin-right:4px;"></i>Gmail / Username
                        </div>
                        <div id="vw-loginemail" style="font-family:monospace;font-size:0.88rem;color:#4ecdc4;font-weight:700;word-break:break-all;"></div>
                    </div>
                    <!-- Temp Password -->
                    <div style="background:rgba(15,15,26,0.7);border:1px solid rgba(168,85,247,0.25);border-radius:10px;padding:12px 16px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.07em;color:rgba(255,255,255,0.35);font-weight:600;margin-bottom:6px;">
                            <i class="fa-solid fa-lock" style="margin-right:4px;"></i>Temporary Password
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div id="vw-temppwd"
                                 style="font-family:monospace;font-size:0.95rem;color:#a855f7;font-weight:800;
                                        letter-spacing:0.05em;flex:1;
                                        filter:blur(4px);transition:filter 0.25s;user-select:none;"></div>
                            <button type="button" id="vw-pwd-toggle"
                                    style="flex-shrink:0;background:rgba(168,85,247,0.15);border:1px solid rgba(168,85,247,0.35);
                                           color:#a855f7;border-radius:8px;padding:4px 10px;font-size:0.78rem;
                                           cursor:pointer;white-space:nowrap;font-weight:600;transition:all 0.2s;">
                                <i class="fa-solid fa-eye" id="vw-pwd-eye"></i> Show
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="viewUserCloseBtn">Close</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// View User Modal
(function() {
    const modal    = document.getElementById('viewUserModal');
    const closeBtn = document.getElementById('viewUserClose');
    const closeFtr = document.getElementById('viewUserCloseBtn');

    function openViewModal(btn) {
        document.getElementById('vw-uid').textContent        = '#' + btn.dataset.id;
        document.getElementById('vw-idcode').textContent     = btn.dataset.idcode || '—';
        document.getElementById('vw-name').textContent       = btn.dataset.name || '—';
        document.getElementById('vw-email').textContent      = btn.dataset.email || '—';
        document.getElementById('vw-phone').textContent      = btn.dataset.phone || '—';
        document.getElementById('vw-gender').textContent     = btn.dataset.gender || '—';
        document.getElementById('vw-address').textContent    = btn.dataset.address || '—';
        document.getElementById('vw-business').textContent   = btn.dataset.business || '—';
        document.getElementById('vw-eventtype').textContent  = btn.dataset.eventtype || '—';
        document.getElementById('vw-guests').textContent     = btn.dataset.guests || '—';
        document.getElementById('vw-notes').textContent      = btn.dataset.notes || '—';
        document.getElementById('vw-registered').textContent = btn.dataset.registered || '—';

        // Login Credentials
        const loginEmail = btn.dataset.loginemail || '';
        const tempPwd    = btn.dataset.temppwd    || '';
        const credsSection = document.getElementById('vw-creds-section');
        const loginEmailEl = document.getElementById('vw-loginemail');
        const tempPwdEl    = document.getElementById('vw-temppwd');
        const pwdToggle    = document.getElementById('vw-pwd-toggle');
        const pwdEye       = document.getElementById('vw-pwd-eye');

        if (loginEmail || tempPwd) {
            credsSection.style.display = '';
            loginEmailEl.textContent = loginEmail || '—';
            tempPwdEl.textContent    = tempPwd    || '—';
            // Reset blur on open
            tempPwdEl.style.filter      = 'blur(4px)';
            tempPwdEl.style.userSelect  = 'none';
            pwdEye.className            = 'fa-solid fa-eye';
            pwdToggle.innerHTML         = '<i class="fa-solid fa-eye" id="vw-pwd-eye"></i> Show';
            pwdToggle.onclick = function() {
                const blurred = tempPwdEl.style.filter !== 'none' && tempPwdEl.style.filter !== '';
                if (blurred) {
                    tempPwdEl.style.filter     = 'none';
                    tempPwdEl.style.userSelect = 'text';
                    pwdToggle.innerHTML        = '<i class="fa-solid fa-eye-slash"></i> Hide';
                } else {
                    tempPwdEl.style.filter     = 'blur(4px)';
                    tempPwdEl.style.userSelect = 'none';
                    pwdToggle.innerHTML        = '<i class="fa-solid fa-eye"></i> Show';
                }
            };
        } else {
            credsSection.style.display = 'none';
        }

        // Role badge
        const roleColors = {Admin:'#e94560',Staff:'#f5a623',Cashier:'#f5a623',Customer:'#4ecdc4'};
        const role = btn.dataset.role || '—';
        const rColor = roleColors[role] || '#aaa';
        document.getElementById('vw-role').innerHTML =
            `<span style="background:rgba(${rColor==='#e94560'?'233,69,96':rColor==='#f5a623'?'245,166,35':'78,205,196'},0.15);color:${rColor};border:1px solid ${rColor}44;padding:2px 10px;border-radius:20px;font-size:0.82rem;font-weight:700;">${role}</span>`;

        // Status badge
        const st = btn.dataset.status || '';
        let stHtml = '';
        if (st === 'active')
            stHtml = '<span style="background:rgba(39,174,96,0.15);color:#27ae60;border:1px solid #27ae6044;padding:2px 10px;border-radius:20px;font-size:0.82rem;font-weight:700;">Active</span>';
        else if (st === 'inactive')
            stHtml = '<span style="background:rgba(150,150,160,0.15);color:#9a9aaa;border:1px solid #9a9aaa44;padding:2px 10px;border-radius:20px;font-size:0.82rem;font-weight:700;">Inactive</span>';
        else if (st === 'rejected')
            stHtml = '<span style="background:rgba(233,69,96,0.15);color:#e94560;border:1px solid #e9456044;padding:2px 10px;border-radius:20px;font-size:0.82rem;font-weight:700;">Rejected</span>';
        else
            stHtml = '<span style="background:rgba(245,166,35,0.15);color:#f5a623;border:1px solid #f5a62344;padding:2px 10px;border-radius:20px;font-size:0.82rem;font-weight:700;">Pending Approval</span>';
        document.getElementById('vw-status').innerHTML = stHtml;

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.btn-view-user').forEach(btn => {
        btn.addEventListener('click', () => openViewModal(btn));
    });
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (closeFtr) closeFtr.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
})();
</script>
<script>
// Live surname/name search for users table
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
})();
</script>
