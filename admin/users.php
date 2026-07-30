<?php
$pageTitle = 'Manage Users';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name    = sanitize($_POST['name']    ?? '');
        $email   = sanitize($_POST['email']   ?? '');
        $role    = in_array($_POST['role'] ?? '', ['admin', 'cashier', 'customer']) ? $_POST['role'] : 'customer';
        $phone   = sanitize($_POST['phone']   ?? '');
        $status  = in_array($_POST['status'] ?? '', ['active', 'inactive', 'banned']) ? $_POST['status'] : 'active';

        if ($action === 'add') {
            $password = $_POST['password'] ?? 'password';
            $hash     = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("INSERT INTO users (name, email, password, role, phone, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $email, $hash, $role, $phone, $status]);
            logAudit($_SESSION['user_id'], 'CREATE_USER', "Created user: {$email} ({$role})", 'users');
            setFlash('success', "User {$name} created.");
        } else {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, role = ?, phone = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $email, $role, $phone, $status, $id]);

            if (!empty($_POST['password'])) {
                $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $pStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $pStmt->execute([$hash, $id]);
            }
            logAudit($_SESSION['user_id'], 'UPDATE_USER', "Updated user #{$id}", 'users');
            setFlash('success', "User updated.");
        }
        redirect(APP_URL . '/admin/users.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id === $_SESSION['user_id']) {
            setFlash('error', 'You cannot delete yourself!');
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            logAudit($_SESSION['user_id'], 'DELETE_USER', "Deleted user #{$id}", 'users');
            setFlash('success', 'User deleted.');
        }
        redirect(APP_URL . '/admin/users.php');
    }
}

$users = $db->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1>Users Management</h1>
        <p>Manage system admins, cashiers, and customer accounts</p>
    </div>
    <button class="btn btn-primary" data-modal="addUserModal">
        <i class="fa-solid fa-user-plus"></i> Add User
    </button>
</div>

<div class="card">
    <div class="search-bar">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-search"></i>
            <input type="text" class="form-control" placeholder="Search users..." data-search-table="usersTable">
        </div>
    </div>

    <div class="table-wrapper">
        <table class="table" id="usersTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="badge badge-<?= $u['role'] === 'admin' ? 'danger' : ($u['role'] === 'cashier' ? 'warning' : 'info') ?>">
                            <?= ucfirst($u['role']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                    <td><?= statusBadge($u['status']) ?></td>
                    <td><?= formatDate($u['created_at']) ?></td>
                    <td>
                        <button class="btn btn-warning btn-sm" data-modal="editUserModal"
                            data-edit='<?= json_encode(['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'phone'=>$u['phone'],'status'=>$u['status']]) ?>'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Delete user <?= htmlspecialchars($u['name']) ?>?">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal">
        <div class="modal-header"><h2 class="modal-title">Add User</h2><button class="modal-close">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" required></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <option value="customer">Customer</option>
                            <option value="cashier">Cashier</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="banned">Banned</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal">
        <div class="modal-header"><h2 class="modal-title">Edit User</h2><button class="modal-close">&times;</button></div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id">
            <div class="modal-body">
                <div class="form-group"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
                <div class="form-group"><label class="form-label">New Password <span style="color:var(--text-muted)">(Leave blank to keep current)</span></label><input type="password" name="password" class="form-control"></div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <option value="customer">Customer</option>
                            <option value="cashier">Cashier</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="banned">Banned</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-warning">Update User</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
