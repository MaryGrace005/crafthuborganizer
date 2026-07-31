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
                          VALUES (?, ?, ?, ?, ?, ?, 'active')")
               ->execute([$name, $email, $hash, $phone, $address, $role]);
            $newId = (int)$db->lastInsertId();
            logAudit($adminId, 'CREATE_USER', "Admin created {$role} account for {$email}", 'users');
            setFlash('success', "Account for {$name} created successfully!");
        } else {
            setFlash('error', implode(' ', $errors));
        }
        redirect(APP_URL . '/admin/users.php');
    }

    // APPROVE customer account (created by staff/cashier)
    if ($action === 'approve') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $db->prepare("UPDATE users SET status = 'active' WHERE user_id = ?")->execute([$uid]);
        logAudit($adminId, 'APPROVE_USER', "Admin approved customer account #{$uid}", 'users');
        setFlash('success', 'Customer account approved and activated successfully!');
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

$pendingCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'inactive' AND role = 'customer'")->fetchColumn();

$sql = "SELECT * FROM users";
if ($roleFilter === 'pending') {
    $sql .= " WHERE status = 'inactive' AND role = 'customer'";
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
    <div class="table-wrapper">
        <table class="table" id="usersTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr style="<?= $u['status'] === 'inactive' ? 'background:rgba(245,166,35,0.04);' : '' ?>">
                    <td><?= $u['user_id'] ?></td>
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
                        <?php if ($u['role'] === 'customer'): ?>
                            <?php if (!empty($u['ip_address'])): ?>
                                <code style="font-size:0.78rem;color:var(--accent-teal);"><?= htmlspecialchars($u['ip_address']) ?></code>
                                <form method="POST" style="display:inline;margin-left:6px;">
                                    <input type="hidden" name="action" value="reset_ip">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm" title="Reset IP — allow login from new device"
                                            data-confirm="Reset IP for <?= htmlspecialchars($u['name']) ?>? They can then log in from any device once.">
                                        <i class="fa-solid fa-rotate"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:0.82rem;"><i class="fa-solid fa-circle-xmark"></i> Not set</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:0.82rem;">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($u['status'] === 'inactive'): ?>
                            <span class="badge badge-warning" style="display:inline-flex;align-items:center;gap:4px;background:rgba(245,166,35,0.18);color:#f5a623;border:1px solid rgba(245,166,35,0.35);">
                                <i class="fa-solid fa-clock"></i> Pending Approval
                            </span>
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
                            <?php if ($u['status'] === 'inactive'): ?>
                                <form method="POST" style="display:inline;margin:0;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm" title="Approve Customer Account"
                                            data-confirm="Approve and activate customer account for <?= htmlspecialchars($u['name']) ?>?">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </button>
                                </form>
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
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label">First Name <span style="color:var(--accent-red);">*</span></label>
                        <input type="text" name="first_name" class="form-control" placeholder="e.g. Maria" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" placeholder="e.g. Cruz">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Surname <span style="color:var(--accent-red);">*</span></label>
                    <input type="text" name="surname" class="form-control" placeholder="e.g. Santos" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address <span style="color:var(--accent-red);">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="customer@email.com" required>
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
                    <input type="text" name="password" class="form-control" placeholder="Min. 6 characters" required>
                    <div class="form-hint">Share this password securely with the customer in person.</div>
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
